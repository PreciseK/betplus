# tools/phpstan

Custom PHPStan rules enforced across every PHP workspace: only `Domain/Wallet/Ledger/LedgerWriter.php`
may write the ledger tables, money fields must carry the `*Kobo` integer type, and the
prohibited-RNG functions (`rand()`, `mt_rand()`, `shuffle()`, `array_rand()`, `str_shuffle()`)
never appear in the game or money path (`REQ-RNG-008`).
