# px-next — self-hosted fonts (attribution & license)

These font files are redistributed **unmodified** as part of the PRODEX `px-next`
design system. They are only referenced by the development-only playground
(`/app/_ui`, `resources/src/assets/styles/sass/px-next/_typography.scss`) and are
**not emitted in a production build**.

## IBM Plex Sans · IBM Plex Mono

- **Copyright** © 2017 IBM Corp. with Reserved Font Name "Plex".
- **License:** SIL Open Font License, Version 1.1 — full text in [`OFL.txt`](./OFL.txt).
- **Upstream:** https://github.com/IBM/plex
- **Obtained from:** `@fontsource/ibm-plex-sans@5.2.6` and
  `@fontsource/ibm-plex-mono@5.2.6` (jsDelivr CDN), `latin` subset, `normal` style.
- The fonts are not sold or bundled on their own; the Reserved Font Name "Plex"
  is not used for any modified version (no modification was made).

| File | Weight | SHA-256 |
|---|---|---|
| `plex-sans-400.woff2` | 400 | `3b646991d30055a93a4ecc499713d4347953a74a947ecab435ab72070cbdab0e` |
| `plex-sans-500.woff2` | 500 | `0717336fb31fcdcde4b8deb3675bb4a0f7f6d484864afcd6751ac29975962203` |
| `plex-sans-600.woff2` | 600 | `8960851d691c054ed38e259bdcf1a6190d157b4203ed5bb32c632a863fb8ec2f` |
| `plex-sans-700.woff2` | 700 | `42e7b0c143c19df9d99fd896e76b48f846edf0902d200bc29796b34d12c33aa7` |
| `plex-mono-400.woff2` | 400 | `3c5a451f9ec27a354b0c2bcca636c6ec17a651281aabf29f8427e210a1d31e85` |
| `plex-mono-500.woff2` | 500 | `756026ff72eb76fd971ac4b7504cec55eef62109d2684c2cad8da32170b80b37` |
| `plex-mono-600.woff2` | 600 | `c4d3deb734a27e6d0dc7a6b464779f70ba1c272e26287860a14e35e85acb5b76` |

## Fonts evaluated in Fase A but not adopted

Source Sans 3 (OFL-1.1) and Public Sans (OFL-1.1) were compared against IBM Plex
Sans on an identical specimen. IBM Plex Sans + Mono was approved; the other two
font files were **removed** from the codebase to avoid unused assets. The written
comparison is kept in the playground's typography section for reference.
