# Phase 1 – Cyberpunk UI Design

## Status

**Design preview only.** The repository did not have an application UI before Phase 1. We have created a reusable theme and public-page shell without activating registration, login, personal-data collection, or bill storage. The proposal is still awaiting instructor approval.

## Visual direction

The selected style is cyberpunk: dark terminal surfaces, neon accents, thin HUD borders, faint CRT scanlines, and a restrained hover glitch effect.

| Token | Hex | Intended use |
| --- | --- | --- |
| Primary green | `#00FF00` | Headings, main actions, emphasis |
| Magenta | `#FF00FF` | Decorative glitch/accent |
| Cyan | `#00FFFF` | Links, focus indicators, dashboard accents |
| Near-black | `#0D0D0D` | Page background |
| Yellow | `#FFD700` | Notices |
| Red | `#FF0000` | Danger token; lighter error labels are used where readable |
| Body text | `#E4EAF0` | Readable copy on dark backgrounds |

Fonts: JetBrains Mono, Fira Code, and system monospace fallbacks. No external font dependency is required.

## Public pages now in the repository

| Path | Purpose | Current behavior |
| --- | --- | --- |
| `public/index.php` | Public homepage and sample dashboard illustration | Illustrative amounts only |
| `public/login.php` | Log-in visual preview | Inputs and submission intentionally disabled |
| `public/register.php` | Proposed registration-field overview | No registration form yet; awaits signed approval |
| `public/privacy.php` | Privacy Notice layout draft | Marked incomplete pending approved proposal |
| `php/layout.php` | Shared page name above header, navigation, footer, safety headers | Guest routes only |
| `public/css/cyberpunk.css` | Design tokens, components, layout, motion, breakpoints | Accessible focus styles and reduced-motion handling |

## Try it on Windows

Use PHP 8.x. In the cloned Project404 root, run:

```powershell
# If PHP is already on PATH:
php -S 127.0.0.1:8000 -t public

# Otherwise use the PHP included with XAMPP:
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public
```

Open **http://127.0.0.1:8000/** in your browser. The built-in server is for local development only.

When using Apache/XAMPP later, make sure the configured site **DocumentRoot points to `Project404/public`** (rather than the repository root). Sensitive configuration, server-side modules, and scripts must be outside the served folder. Do not place a real `config.php` inside `public/`.

## Design constraints carried from the handout

- The system name appears **above** the header on every public page.
- Navigation links sit in the upper-right on desktop. Login only has Home and Register; Register only has Home and Log-in.
- Visitors never see a Log-out link.
- Field labels are above inputs. Future required fields get a red `*`, optional fields a red 'optional' label; ID placeholder will be `xxxx-xxxx`.
- Validation errors will be shown immediately below their fields.
- All CSS is in the `css/` folder. Future JavaScript will go in `js/`; no inline JavaScript.
- No unauthenticated user or preview page can view real user dashboards or security logs.

## Accessibility notes

- Body text and panel text use high-contrast light tones on near-black surfaces.
- Neon-green buttons use black text for legibility.
- Focus indicators use a visible cyan outline.
- Hover glitch is decorative, not essential information, and is suppressed under `prefers-reduced-motion: reduce`.
- CRT scanlines are subtle, the design adapts to narrow screens, and all navigation links have text labels.
- The sample bar chart is decorative and backed by explicit sample figures, not presented as live data.

## Future integration

After the instructor approves Appendix A, implement registration validation, then log-in with CAPTCHA and OTP, followed by protected dashboards. Preserve these CSS classes and shared page layout where possible. **All authentication, ownership checks, and rate limits must be enforced in PHP**, never only in the frontend.
