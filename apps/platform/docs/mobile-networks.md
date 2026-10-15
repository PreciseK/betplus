# Player mobile networks

Registration records the player's selected network: `mtn`, `glo`, `airtel`, or `t2`.
The web form requires an explicit choice. We do not infer networks from phone prefixes because numbers can be ported.

`POST /v1/auth/register` accepts `mobile_network`. The API validates it and saves it on `signupSession.mobileNetwork`. Account creation copies the value from the verified signup session to `player.mobileNetwork`, which has an index for grouping and filtering.

The API field remains optional for existing clients and USSD flows. Existing accounts have NULL (not recorded); no historical values are guessed. A registration attempt for an existing number does not update that player's network.

Apply the schema change from `apps/platform`:

```sh
php artisan migrate --path=database/migrations/2026_10_15_000100_add_mobile_network_to_registration.php
```

View current counts:

```sh
php artisan players:network-counts
```

The report includes each network, Not recorded, and Total. It counts completed player accounts, including active, frozen and closed accounts; excludes deleted accounts and incomplete signups. These are player-reported networks at registration, not a live carrier lookup.

The T2 logo in `apps/web/public/assets/providers/t2.png` comes from the [official T2 website](https://t2mobile.com.ng/), asset `/_next/static/media/logos.1d851e63.png`. The other network logos reuse the project's existing assets.
