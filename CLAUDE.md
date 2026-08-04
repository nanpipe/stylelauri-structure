# StyleLauri Order Flow — project instructions

WooCommerce plugin in `stylelauri-order-flow/`. Live test env at `C:\wp-demo` (WooCommerce + SQLite + wp-cli, PHP at `C:\php83`).

## Domain context (why this plugin exists)

StyleLauri.com — K-pop merch store selling both immediate stock and **presale-by-lot** campaigns. The core problem: a single "order status" was encoding 5 things at once (lifecycle stage, delivery method, campaign/lote, payment state, production). This plugin splits those apart. Non-obvious external constraints that drive the whole design:

- **Skydrops** (courier integration) only sees orders in the native `processing` status. So `processing` ("Merch Lista") is the FINAL, dispatchable stage — nothing internal may sit there.
- **Wompi** (payment gateway) auto-moves any paid order to `processing`. So a "dispatch gate" router must intercept those and funnel them back into the pipeline first.
- **YAYMail** owns all customer emails. The plugin creates NO emails — it only exposes order metadata + totals rows for templates, and fires the `slo_saldo_reminder` hook.
- Runs on **HPOS** (custom order tables).

## Architecture invariants (don't break these)

- The plugin creates NO order statuses. The STORE creates them (via its status plugin); the plugin only maps store statuses to lifecycle ROLES in StyleLauri > Ajustes. Roles: `abono`, `produccion`, `preventa`, `listo`, `boletas` (see `SLO_Order_Statuses::roles()`).
- Dispatch is HARDWIRED to native `processing`, mandatory (not a role). Mapping any role to `processing` is rejected everywhere (`get_status()` returns '' for it) — it would silently defeat the gate.
- Two absolute Merch Lista rules: an order NEVER reaches `processing` (a) without passing through Preparación (packing, `_slo_paso_por_listo`), or (b) with an unpaid balance.
- Balance = derived from order fee lines (cuota model): saldo = Abono Reserva negative fee − sum of "… de cuota" positive fees. Not a parallel meta ledger.
- Snapshot (`_slo_lotes_pedido` + governing/latest `_slo_fecha_despacho`) is written at order creation and is deliberately frozen once terminal (`completed`/`cancelled`/`refunded`).
- Preventa lock: a presale order can't enter Preparación until its lote is ready — governing dispatch date reached, lote marked **Producido** (term meta `slo_lote_producido`), or per-order manual release (`_slo_preventa_liberado`). A blocked move leaves the order WHERE IT IS (don't drag it back to Abono Producción — that's the label-printing stage). The "Liberar" button auto-advances to Preparación ONLY from the Preventa status.
- Eventos exception: an order whose line items are ALL in the configured events category diverts to the `boletas` role on payment and skips the physical funnel. A mixed order (event + merch) follows the normal funnel. Needs the `boletas` role mapped and a category set.

Recommended store status slugs per role (the store adds the `wc-` prefix): `abono`→saldo-pendiente, `produccion`→abono-produccion, `preventa`→preventa, `listo`→preparacion, `boletas`→boletas.

Lifecycle flow: Pago → **Abono Producción** (print label) → *(manual)* **Preventa** (waiting for lote) → **Preparación** (packing) → **Merch Lista** (`processing`, Skydrops dispatch). Branches: unpaid balance → **Saldo Pendiente**; 100%-events order → **Boletas** (out of the physical funnel).

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
