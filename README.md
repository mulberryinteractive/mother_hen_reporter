# Mother Hen Reporter

A small Drupal module that reports a site's installed Drupal core, module, theme and Composer package versions to a [Mother Hen](#about-mother-hen) security dashboard. The dashboard matches those versions against Drupal security advisories.

The module only pushes outbound. It adds no routes, and nothing can query the site for its versions. Each report is signed with a private key that stays on the site.

## What is sent

Each report is a JSON document containing:

| Field | Contents |
|---|---|
| `site`, `environment` | The site ID assigned by the dashboard and the environment name |
| `drupal.core_version`, `php_version` | Drupal core and PHP versions |
| `packages` | Every installed Composer package: name, version, source reference (commit), type, whether it is a dev requirement. The root project is excluded. |
| `extensions` | Every available module and theme: machine name, drupal.org project, version, whether it is installed |
| `patches` | The contents of `patches.lock.json` (composer-patches 2.x), if present |
| `captured_at`, `reporter_version`, `schema` | Report metadata |

The module never sends content, users, configuration, credentials or file paths. To see exactly what would be sent, run `drush mother-hen:payload`.

## When reports are sent

- During cron, if the inventory has changed since the last accepted report, or if an hour has passed since then.
- From any environment where the private key has a value. Each report names its environment:
  - on Upsun or Platform.sh, the environment's branch name (`PLATFORM_BRANCH`);
  - elsewhere, the **Environment name** setting, which defaults to `production`.

  Each environment is stored separately. The dashboard keeps reports only from the environments monitored for that site (its production environment plus any others listed, wildcards allowed). It lists other environments that report, so you can add them.
- Only over HTTPS.

If a report fails, the next cron run retries it. The **Status report** page (`/admin/reports/status`) shows the last result.

## Requirements

- Drupal 10.3 or later, or Drupal 11.
- The [Key](https://www.drupal.org/project/key) module, installed automatically by Composer.
- PHP 8.1 or later with the `sodium` extension, which is bundled with PHP by default.
- Composer 2.
- Cron running at least daily. The dashboard flags a site as stale once its last report is older than that site's threshold, which defaults to 26 hours.
- Outbound HTTPS from the site to the dashboard.

## Setting up a site

Do steps 1–4 locally and commit the result. Then deploy, and give production the private key.

### 1. Install the module

```sh
composer config repositories.mother_hen_reporter vcs https://github.com/mulberryinteractive/mother_hen_reporter
composer require 'mulberryinteractive/mother_hen_reporter:^2.0'
drush pm:install mother_hen_reporter
```

### 2. Generate a key pair

```sh
drush mother-hen:keygen
```

It prints a public key and a private key. Each site gets its own pair.

- **Private key:** keep it for step 6. Never commit it or put it in configuration.
- **Public key:** you need it in step 3. It is also shown on the settings form once the key is configured.

### 3. Add the site to the dashboard

On the dashboard, open **Sites → Add a site**:

- Enter the production environment name. On Upsun this is the production branch, usually `main`.
- List any other environments that should report.
- Paste the public key.

Then save, and copy the site's **Site ID** from the Sites list.

### 4. Configure the reporter

1. **Create a key.** At **Configuration → System → Keys → Add key**:
   - Key type: *Authentication*.
   - Key provider: *Environment*.
   - Environment variable: `MOTHER_HEN_PRIVATE_KEY`.

   The secret then stays out of configuration and the database. The settings form warns if you choose a key stored anywhere else.
2. **Fill in the settings form.** At **Configuration → System → Mother Hen reporter**:
   - **Dashboard endpoint:** `https://YOUR-DASHBOARD/api/mother-hen/inventory`.
   - **Site ID:** from step 3.
   - **Private key:** the key you just created.
3. **Export and commit the configuration:**
   - `drush cex`
   - Commit `config/sync`, `composer.json` and `composer.lock`.

None of this configuration is secret. It is the same in every environment, and only environments where the key has a value send reports.

### 5. Deploy

Deploy as usual. The configuration import enables the module. It stays silent until step 6.

### 6. Give production the private key

**Upsun / Platform.sh.** Create a sensitive environment variable on the production environment, here `main`:

```sh
upsun variable:create -p PROJECT -l environment -e main --prefix env: --name MOTHER_HEN_PRIVATE_KEY \
  --value 'PRIVATE-KEY-FROM-STEP-2' --sensitive true --inheritable false
```

`--inheritable false` means only production reports. With `--inheritable true`, child environments such as staging and previews inherit the key and report too; the dashboard keeps only the environments you monitor. Every environment holding the private key can sign reports for the site, so only let it reach environments built from branches your team controls.

Older Platform.sh projects use the `platform` CLI, with the same flags.

**AWS and other hosts.** Provide `MOTHER_HEN_PRIVATE_KEY` to PHP as an environment variable on each environment that should report. Set **Environment name** on the settings form (or in `settings.php`, see below) if the default `production` is not right.

Then send a first report from production:

```sh
drush mother-hen:report     # Sends a report now and prints the result.
```

Check **Status report → Mother Hen reporter** on the site, and the site's **Last report** time on the dashboard.

### Overriding settings in settings.php

Any setting can be overridden per environment with Drupal's standard configuration overrides:

```php
$config['mother_hen_reporter.settings']['environment'] = getenv('APP_ENV') ?: 'production';
$config['mother_hen_reporter.settings']['endpoint'] = 'https://other-dashboard.example/api/mother-hen/inventory';
```

### Upgrading from 1.x

Version 1 read `$settings['mother_hen_reporter']` from `settings.php`. Version 2 ignores that block, and the status report warns while it is still there.

1. Require `^2.0`.
2. Create the key and fill in the settings form as in step 4. The existing `MOTHER_HEN_PRIVATE_KEY` variable keeps working.
3. Export the configuration and remove the old block from `settings.php`.
4. `MOTHER_HEN_SITE_ID` is no longer needed; the site ID is now configuration.

## Drush commands

| Command | Purpose |
|---|---|
| `mother-hen:keygen` | Generate a key pair for a new site |
| `mother-hen:payload` | Print the JSON payload that would be sent |
| `mother-hen:report` | Send a report now, ignoring the schedule |

## Rotating a key

1. Run `drush mother-hen:keygen`.
2. Paste the new public key into the dashboard.
3. Replace the `MOTHER_HEN_PRIVATE_KEY` secret wherever it is set.

Reports signed with the old key are refused once the dashboard has the new public key, so update both sides together.

## Security notes

- **The signature binds the request.** Each request carries the site ID, a timestamp and a random nonce, signed with Ed25519 together with the SHA-256 of the body. The dashboard rejects:
  - bad signatures;
  - timestamps more than 5 minutes off;
  - reused nonces;
  - bodies over 2 MB.

  Valid reports from environments the site does not monitor are accepted but discarded.
- **The dashboard stores only public keys.** A leak of the dashboard's database cannot be used to forge reports.
- **Reports are as accurate as installed versions.** A module patched without changing its version still reports the original version. composer-patches 2.x users get `patches.lock.json` included, so patched packages are visible.

## Request format

```
POST /api/mother-hen/inventory
Content-Type: application/json
X-Mother-Hen-Site: <site UUID>
X-Mother-Hen-Timestamp: <unix seconds>
X-Mother-Hen-Nonce: <32 lowercase hex characters>
X-Mother-Hen-Signature: base64(Ed25519-sign(message))

message = "mother-hen-v1\n{site}\n{timestamp}\n{nonce}\n{sha256_hex(body)}"
```

The dashboard answers `202` when it accepts a report. Other responses:

- `400`: the body is not JSON.
- `401`: the signature, timestamp or nonce check failed.
- `413`: the body is too large.
- `422`: the payload is invalid, for example the environment doesn't match.
- `429`: too many failed attempts.

The unit test `tests/src/Unit/SignerTest.php` contains a fixed test vector. The dashboard's tests check the same vector, so any change to the scheme breaks one side's tests.

## Development

This repository uses [DDEV](https://ddev.com) with the [ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on, which builds a Drupal site around the module in `web/` (git-ignored):

```sh
ddev start
ddev poser             # Install Drupal core and dev dependencies.
ddev symlink-project   # Link the module into web/modules/custom.
ddev phpunit           # Run the tests.
ddev phpcs             # Check coding standards.
```

To test against a local dashboard:

1. Install a site with `ddev drush site:install minimal -y` and enable the module.
2. Configure it at **Configuration → System → Mother Hen reporter**, with a key using the *Configuration* provider. That is acceptable for local testing only, and the form warns about it.
3. Point it at the dashboard's DDEV web container, `http://ddev-mother-hen-web/api/mother-hen/inventory`.
4. Tick **Development → Allow a plain HTTP endpoint**.

Test with Drupal 10 by running `ddev core-version '~10.3.0'`, then `ddev poser`.

## About Mother Hen

Mother Hen is a Drupal-based dashboard that collects Drupal security advisories and shows which managed sites are affected and what needs attention first.

## License

GPL-2.0-or-later.
