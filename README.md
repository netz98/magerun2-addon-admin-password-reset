# n98-magerun2 Admin Password Reset

Adds `admin:user:force-password-reset` to n98-magerun2.

The command replaces each selected user's password with a cryptographically random value, marks the account for a new password, and creates a normal Magento reset token. The random value is never printed or emailed.

## Requirements

- PHP 8.1 or later
- Magento 2 with n98-magerun2

## Installation

Install the module with Composer:

```bash
composer require n98/ext.magerun2.n98.admin-password-reset
```

n98-magerun2 loads modules from `lib/n98-magerun2/modules`. Add the following command to the root project's `post-install-cmd` scripts so the Composer package is linked automatically after installation:

```json
{
  "scripts": {
    "post-install-cmd": [
      "mkdir -p lib/n98-magerun2/modules && ln -rsfT vendor/n98/ext.magerun2.n98.admin-password-reset lib/n98-magerun2/modules/admin-password-reset"
    ]
  }
}
```

The module is loaded automatically when n98-magerun2 starts once the link exists.

## Usage

Always preview first:

```bash
n98-magerun2 admin:user:force-password-reset --all --dry-run
```

Exactly one selector is required. Selectors that accept multiple values can be repeated:

```bash
n98-magerun2 admin:user:force-password-reset --username admin --send-email --yes
n98-magerun2 admin:user:force-password-reset --username admin --username editor --yes
n98-magerun2 admin:user:force-password-reset --user-id 12 --invalidate-sessions --yes
n98-magerun2 admin:user:force-password-reset --role-id 1 --send-email --invalidate-sessions --yes
n98-magerun2 admin:user:force-password-reset --all --exclude-username admin --send-email --yes
```

### Options

| Option | Description |
| --- | --- |
| `--all` | Select all active admin users. |
| `--username <name>` | Select users by username. Repeatable. |
| `--user-id <id>` | Select users by ID. Repeatable. |
| `--role-id <id>` | Select users assigned to an authorization role. Repeatable. |
| `--exclude-username <name>` | Exclude users by username. Repeatable and compatible with `--all`. |
| `--include-inactive` | Include inactive users in the selection. |
| `--send-email` | Send Magento's standard password-reset email to active users. Inactive users are reset without an email. |
| `--invalidate-sessions` | Log out tracked active sessions for each selected user. |
| `--dry-run` | List matching users without changing anything. |
| `--yes` / `-y` | Confirm the destructive operation. |

`--send-email` and `--invalidate-sessions` are opt-in. Without `--send-email`, users must use Magento's Forgot Password form to request a new reset email. The command requires `--yes` unless `--dry-run` is used.

Inactive users included with `--include-inactive` receive a new password but never receive a password-reset email, even when `--send-email` is specified.

## Safety

- Run a dry run before every reset to verify the selected accounts.
- Only active users are selected by default.
- The command never displays or sends the generated random password.
- Password changes are processed one user at a time; a failure stops further processing and returns a failure status.
