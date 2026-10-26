# Runbook — Release Packages

How a GoProperty release zip is built, checked and published. The package itself is specified in [INSTALLER.md § 2](../../INSTALLER.md); installing from it is [shared-hosting.md § 2](shared-hosting.md) and [INSTALLER.md](../../INSTALLER.md).

The zip is for installs and upgrades **from a file upload** (no SSH, or a manual upgrade, [INSTALLER.md § 10](../../INSTALLER.md)). GoProperty's own servers are normally updated by the SSH **Deploy** workflow, which ships a plain tarball instead ([shared-hosting.md § 3](shared-hosting.md)).

## 1. Cutting a release

1. Make sure `main` is green in CI.
2. Pick the version ([semantic versioning](https://semver.org): `1.4.0`; release candidates `1.4.0-rc.1`).
3. Tag and push:
   ```bash
   git tag -a v1.4.0 -m "GoProperty 1.4.0"
   git push origin v1.4.0
   ```
4. The **Release** workflow ([`.github/workflows/release.yml`](../../.github/workflows/release.yml)) then:
   1. runs the quality gate and the install drill on every database (the `CI` and `Install drill` workflows);
   2. builds `goproperty-1.4.0.zip` and `goproperty-1.4.0.zip.sha256` with `scripts/release/build-package.sh`;
   3. runs the release drill (§ 3);
   4. publishes a GitHub release for the tag with both files and generated notes. Versions with a suffix (`-rc.1`) are marked as pre-releases.

If any step fails, nothing is published. Fix the problem on `main`, delete the tag (`git push origin :refs/tags/v1.4.0` and `git tag -d v1.4.0`), and tag again.

**Test package without publishing:** Actions → Release → *Run workflow*, on any branch, with a version such as `1.4.0-rc.1`. The zip is kept as a workflow artifact for 7 days.

## 2. What is in the zip

```
README.txt                     which layout to use
preferred/goproperty/          document root → goproperty/public
split/goproperty-app/          the application, next to public_html
split/public_html/             public files only; index.php points at ../goproperty-app
```

Each layout holds the application code, production `vendor/`, built assets in `public/build/`, `.env.example`, empty `storage/` and `bootstrap/cache/` folders, `VERSION` and `SHA256SUMS`. The split layout also has `bootstrap/public-path.php`, which tells artisan and cron that the public files are in `../public_html`.

Building locally (needs PHP 8.4 with `zip`, Composer, Node 22, and the `zip` command):

```bash
scripts/release/build-package.sh 1.4.0-dev.1 dist
scripts/release/verify-drill.sh dist/goproperty-1.4.0-dev.1.zip
```

The build uses only tracked and new (not ignored) files, so a local `.env`, `vendor/` or `node_modules/` never ships. The same commit builds a zip with the same entries, order and timestamps.

## 3. Release drill

`scripts/release/verify-drill.sh <zip>` uses the package's own `artisan`, as an owner would:

| Check | Expected |
| --- | --- |
| `goproperty:verify-package <zip>` | passes |
| `goproperty:verify-package` inside each unpacked layout | passes |
| one public file changed | refused: "does not match its SHA256SUMS checksum" |
| one file added to `public_html` | refused: "not listed in SHA256SUMS" |
| one zip entry changed | refused by the inner checksum and by the `.sha256` file |
| both layouts served by `php -S`, fresh upload | `/` and `/login` redirect to `/install`; a POST gets 503; `/install` and `install.css` return 200 |
| the same with `APP_KEY` but not installed | `/up` 200, `/` 503 "being set up", `/install` 200, a built asset 200, nothing written to the log |
| `artisan` and `index.php` on PHP 7.4 and 8.1 (Docker; set `OLD_PHP_IMAGES`) | exit 1 / HTTP 500 with "GoProperty needs PHP 8.4 or newer; this server runs 7.4." |

The CI workflow also runs the old-PHP check on every push (job `Old PHP guard`), so the guard cannot break between releases.

**Install drill** (`scripts/release/install-drill.sh <zip>`, needs `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` for an account that can create databases). It installs the split layout with `goproperty:install --no-interaction` under shared-host limits and checks:

- a second install is refused;
- `check-installation` passes after one cron run;
- `/` and `/login` return 200 and `/install` 404;
- `verify-package` passes on the installed copy;
- the schema is identical to a plain `migrate` of the same release.

Both drills run before every release.

## 4. Verifying on the server

After uploading and extracting, before opening `/install`:

- Before uploading, compare the zip's SHA-256 with the `.sha256` file on the release page (`sha256sum goproperty-1.4.0.zip`, or `Get-FileHash` in Windows PowerShell).
- With SSH, from the application folder (`goproperty/` or `goproperty-app/`):
  ```bash
  php artisan goproperty:verify-package                          # this installation
  php artisan goproperty:verify-package ~/goproperty-1.4.0.zip   # a zip, before extracting it
  ```
  It exits 0 when every file matches `SHA256SUMS`. On a live site it ignores the site's own `.env`, `storage/`, `bootstrap/cache/`, and files hosting panels add (`error_log`, `.user.ini`, `.well-known/`, `cgi-bin/`, cPanel's PHP-handler block in `.htaccess`).
- If the split layout's public folder is not called `public_html` (Plesk: `httpdocs`), edit `goproperty-app/bootstrap/public-path.php`, or pass `--public=/path/to/httpdocs` when checking an unpacked folder.

**A failure** means files differ from what CI built: an incomplete upload, a host or plugin that changed files, or tampering. Re-upload the layout from the original zip. Never "fix" `SHA256SUMS` by hand. Running the verifier later (for example after a suspected compromise) also reports files added to the code folders, such as a dropped PHP shell in `public/`.
