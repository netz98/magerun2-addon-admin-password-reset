<?php

declare(strict_types=1);

namespace N98\AdminPasswordReset\Command;

use Magento\Backend\Helper\Data as BackendDataHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ConfigInterface as SecurityConfig;
use Magento\Security\Model\ResourceModel\AdminSessionInfo\CollectionFactory as SessionCollectionFactory;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\Spi\NotificatorInterface;
use Magento\User\Model\UserFactory;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ForcePasswordResetCommand extends AbstractMagentoCommand
{
    private UserFactory $userFactory;
    private UserCollectionFactory $userCollectionFactory;
    private UserResource $userResource;
    private EncryptorInterface $encryptor;
    private BackendDataHelper $backendDataHelper;
    private NotificatorInterface $notificator;
    private SessionCollectionFactory $sessionCollectionFactory;
    private SecurityConfig $securityConfig;
    private ResourceConnection $resourceConnection;
    private State $appState;

    protected function configure(): void
    {
        $this
            ->setName('admin:user:force-password-reset')
            ->setDescription('Invalidates selected admin passwords and optionally sends reset emails')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Select all active admin users')
            ->addOption('username', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Select username (repeatable)')
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Select user ID (repeatable)')
            ->addOption('role-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Select users assigned to role ID (repeatable)')
            ->addOption('exclude-username', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude username (repeatable)')
            ->addOption('include-inactive', null, InputOption::VALUE_NONE, 'Include inactive admin users')
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send the standard Magento password reset email to active users. Inactive users are reset without an email.')
            ->addOption('invalidate-sessions', null, InputOption::VALUE_NONE, 'Log out tracked active admin sessions')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show selected users without changing anything')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm the destructive operation');
    }

    public function inject(
        UserFactory $userFactory,
        UserCollectionFactory $userCollectionFactory,
        UserResource $userResource,
        EncryptorInterface $encryptor,
        BackendDataHelper $backendDataHelper,
        NotificatorInterface $notificator,
        SessionCollectionFactory $sessionCollectionFactory,
        SecurityConfig $securityConfig,
        ResourceConnection $resourceConnection,
        State $appState
    ): void {
        $this->userFactory = $userFactory;
        $this->userCollectionFactory = $userCollectionFactory;
        $this->userResource = $userResource;
        $this->encryptor = $encryptor;
        $this->backendDataHelper = $backendDataHelper;
        $this->notificator = $notificator;
        $this->sessionCollectionFactory = $sessionCollectionFactory;
        $this->securityConfig = $securityConfig;
        $this->resourceConnection = $resourceConnection;
        $this->appState = $appState;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return self::FAILURE;
        }

        $this->initializeAreaCode();

        try {
            $users = $this->getSelectedUsers($input);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        }

        if ($users->getSize() === 0) {
            $output->writeln('<comment>No matching admin users found.</comment>');
            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $output->writeln(sprintf(' - %s (%s)', $user->getUserName(), $user->getEmail()));
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run: no changes made.</info>');
            return self::SUCCESS;
        }

        if (!$input->getOption('yes')) {
            $output->writeln('<error>This operation changes admin credentials. Re-run with --yes.</error>');
            return self::FAILURE;
        }

        $changed = 0;
        foreach ($users as $user) {
            try {
                $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                $user->setPassword($temporaryPassword);
                $user->setForceNewPassword(true);
                $user->changeResetPasswordLinkToken($this->backendDataHelper->generateResetPasswordLinkToken());
                $user->setData('modified', gmdate('Y-m-d H:i:s'));
                $this->userResource->save($user);
                $this->userResource->trackPassword($user, $user->getPassword());

                if ($input->getOption('send-email') && (bool)$user->getIsActive()) {
                    $this->notificator->sendForgotPassword($user);
                }

                if ($input->getOption('invalidate-sessions')) {
                    $this->invalidateSessions((int)$user->getId());
                }

                $changed++;
                $output->writeln('<info>Reset: ' . $user->getUserName() . '</info>');
            } catch (\Throwable $exception) {
                $output->writeln('<error>Failed for ' . $user->getUserName() . ': ' . $exception->getMessage() . '</error>');
                return self::FAILURE;
            }
        }

        $output->writeln(sprintf('<info>%d admin user(s) processed.</info>', $changed));
        return self::SUCCESS;
    }

    private function getSelectedUsers(InputInterface $input)
    {
        $selectors = array_filter([
            $input->getOption('all'),
            $input->getOption('username'),
            $input->getOption('user-id'),
            $input->getOption('role-id'),
        ], static fn ($value): bool => $value !== false && $value !== []);

        if (count($selectors) !== 1) {
            throw new \InvalidArgumentException('Specify exactly one selector: --all, --username, --user-id, or --role-id.');
        }

        $collection = $this->userCollectionFactory->create();
        if (!$input->getOption('include-inactive')) {
            $collection->addFieldToFilter('is_active', 1);
        }

        if ($input->getOption('username')) {
            $collection->addFieldToFilter('username', ['in' => $input->getOption('username')]);
        } elseif ($input->getOption('user-id')) {
            $collection->addFieldToFilter('user_id', ['in' => array_map('intval', $input->getOption('user-id'))]);
        } elseif ($input->getOption('role-id')) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()->from(
                $this->resourceConnection->getTableName('authorization_role'),
                'user_id'
            )->where('parent_id IN (?)', array_map('intval', $input->getOption('role-id')))
                ->where('user_id IS NOT NULL');
            $collection->addFieldToFilter('user_id', ['in' => $connection->fetchCol($select)]);
        }

        $exclude = $input->getOption('exclude-username');
        if ($exclude) {
            $collection->addFieldToFilter('username', ['nin' => $exclude]);
        }

        return $collection;
    }

    private function invalidateSessions(int $userId): void
    {
        $collection = $this->sessionCollectionFactory->create()
            ->filterByUser($userId, AdminSessionInfo::LOGGED_IN)
            ->filterExpiredSessions($this->securityConfig->getAdminSessionLifetime())
            ->loadData();

        $collection->setDataToAll('status', AdminSessionInfo::LOGGED_OUT_MANUALLY)->save();
    }

    private function initializeAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
    }
}
