# Landing page redesign — what changed

Scope: `index.html` (landing `#landing` only), `style.css`, `script.js`. CSS cache-bust bumped `style.css?v=20261006e` -> `?v=20261006f`. No frameworks/deps added. Login/register/dashboard/bulletin/policy sections untouched. Global navbar left as-is (restyled only via a new in-hero identity band to avoid cross-page regressions).

## 1. Typography / letter-spacing
- Negative tracking now applies ONLY to the hero `h1` (`-.03em`). Reset to `normal` on `.accent-text`, `.description`, `.hero-helper`, `.hero-privacy`, `.doc-chips li`, `.doc-facts li`, `.step-item`/`h3`/`p`, `.service-card`/`strong`/`p`, `.how-head h2`, `.hero-docs h2`, and the identity band text.
- The accent span `now online` keeps a normal word gap (no tight tracking), so it no longer reads as `nowonline`.

## 2. Contrast (WCAG, older users)
- Secondary/body copy moved off `--color-muted`/`--color-quiet` to `--color-text` (#57534e): hero description, helper line, privacy line, doc facts, step/service card paragraphs, identity sublabels.
- Helper line enlarged from `--text-12` to `--text-13` and darkened.

## 3. Layout balance
- Removed the old `.hero-split` where the right "steps" card ended mid-hero leaving a dead zone.
- Hero is now a 2-column band: intro (CTAs + privacy) left, a "Documents you can request" panel right. Both columns are `display:flex` and stretch to equal height (privacy line and doc-facts pinned to the bottom with `margin:auto 0 0`), so no dead whitespace.
- The 3-step "how it works" moved OUT of the hero into its own full-width band (`.how-it-works`) below, 3 steps in a row (stacks on mobile).

## 4. Deduplicated messaging
- HERO: only the two CTAs (Register / Log in) + one helper line.
- STEPS BAND: the only place describing the process.
- BOTTOM CARDS (`.service-strip`) are now distinct destinations: Request a document (`checkAuthAndRedirect('request')`), Public records (`showSection('bulletin')`), Help & contact (new `focusContactInfo()` → scrolls/focuses the contact row). Removed the redundant "Create an account" card.

## 5. Stats row replaced
- Dropped `4 / 24-7 / Verified`. Added real document chips: Barangay Clearance, Certificate of Residency, Certificate of Indigency, Business Permit — plus honest facts: "Typical processing: 1-3 working days", "Pick up at the Barangay Hall", "Fees vary by document" (no invented peso amounts).

## 6. Copy / plain language
- Cut the bureaucratic "Digital Disclosure & Document Request Portal" subtitle. Single subtitle now: "Request barangay certificates and clearances online. Pick them up at the Barangay Hall when they're ready."
- "SK" expanded to "youth council (SK)" on the Public records card.

## 7. Official identity / trust
- New top identity band (`.gov-identity`) on the landing: larger 72px seal + "Republic of the Philippines · Biringan City" / "Barangay San Jose" / sublabel. Seal is now prominent vs the old 48px.
- Background flattened: replaced the animated blurred `body::before`/`body::after` gradient blobs (`ambient-drift`/`blob-drift`) with a near-flat vertical tint. `prefers-reduced-motion` block retained.

## 8. Smaller details
- Log in button icon changed `fa-file-lines` → `fa-arrow-right-to-bracket` (login icon).
- Service cards now clearly clickable: accent border, subtle shadow, stronger/accent arrow, hover lift, explicit `:focus-visible` outline. Still real `<button>`s inside a `<nav>` (keyboard-accessible).
- Data Privacy line added under the hero CTAs referencing RA 10173.
- Contact/office-hours `.trust-row` kept, given `id="contact-info"` + `tabindex="-1"` so the Help card can scroll to and focus it.
- No fake FIL/EN toggle added (out of scope).

## Behavior preserved
- `#hero-guest-actions`, `#hero-user-actions`, `#hero-guest-helper` ids kept → `updateNavBar()` guest/user toggle still works (logged-in "Go to my dashboard" / "View public records" variant intact).
- Added `focusContactInfo()` to `script.js`.

## Verification
- Served via XAMPP at http://localhost/barangay_portal_new/index.html (HTTP 200).
- CSS braces balanced (379/379).
- Rendered with headless Chrome at desktop 1280, tablet 720, mobile 390 (inside correctly-constrained iframes). No horizontal overflow; text wraps; chips/steps stack cleanly; tap targets ≥44px on mobile.
- Evidence: `evidence/after-1280.png` (desktop), `evidence/after-720.png`, `evidence/after-responsive.png` (390 + 720 side-by-side in constrained frames).
- Note: a raw `--window-size=390` headless shot showed false clipping (headless layout-viewport quirk); the iframe-constrained renders are the accurate ones and show no overflow.
