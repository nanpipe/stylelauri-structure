# StyleLauri Order Flow — project instructions

WooCommerce plugin in `stylelauri-order-flow/`. Live test env at `C:\wp-demo` (WooCommerce + SQLite + wp-cli, PHP at `C:\php83`).

## Building the install zip (READ THIS — recurring trap)

The Bash tool runs git-bash, whose `tar` is **GNU tar** — it makes a tarball even when the file is named `.zip`, and WordPress rejects it with "Archivo incompatible / Incompatible Archive". Only libarchive **bsdtar** produces a real zip.

Build with the Windows bsdtar explicitly, from the repo root:

```bash
cd /c/Users/nanpi/Claude-Training/stylelauri-structure
rm -f stylelauri-order-flow.zip stylelauri-order-flow/stylelauri-order-flow.zip
/c/Windows/System32/tar.exe -a -cf stylelauri-order-flow.zip stylelauri-order-flow
```

Then ALWAYS verify before delivering:

```bash
file stylelauri-order-flow.zip                      # must say "Zip archive data", NOT "tar archive"
/c/Windows/System32/tar.exe -tf stylelauri-order-flow.zip | grep -c '\\'   # must be 0 (no backslash paths)
```

Notes:
- Entries must be forward-slash and top-level `stylelauri-order-flow/...` (never a stray `.zip` nested inside the plugin folder).
- Do NOT use PowerShell `Compress-Archive` — it writes backslash separators that break install on Linux/Hostinger.
- `build-zip.bat` (double-click / run from cmd.exe) is correct: cmd's `tar` resolves to System32 bsdtar. Only the Bash-tool path is the trap.
- The zip is gitignored (local file only). No commit needed — deliver the file itself with SendUserFile. Install in WP: Plugins > Añadir > Subir plugin > "Reemplazar el actual con el subido".

## Running the functional test suite

The suite runs against the plugin INSTALLED in wp-demo, so sync the working copy there first:

```bash
cp -r /c/Users/nanpi/Claude-Training/stylelauri-structure/stylelauri-order-flow/* /c/wp-demo/site/wp-content/plugins/stylelauri-order-flow/
cd /c/wp-demo/site && /c/php83/php.exe ../wp-cli.phar eval-file "C:/Users/nanpi/Claude-Training/stylelauri-structure/tests/slo-test.php"
```

Expect "ALL N CHECKS PASSED". Also `php -l` every modified file before running.

## Release checklist

1. Bump `Version:` header AND `SLO_VERSION` in `stylelauri-order-flow/stylelauri-order-flow.php`, and `Stable tag` in `readme.txt`.
2. Add a `readme.txt` changelog entry.
3. `php -l` modified files → sync to wp-demo → run the suite (all green).
4. Build + verify the zip (see above).
5. Commit + push (zip is gitignored, not part of the commit).
