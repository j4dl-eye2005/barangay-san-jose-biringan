# Implementation Plan — Register Page Layout & Spacing Refinement

**Scope:** Layout, spacing, and typography only. All field names, `name=` attributes,
validation logic, `handleRegister()`, API calls to `api/register.php`, green palette
CSS variables, seal image, Plus Jakarta Sans font, and button styles are untouched.

**Files touched:** `index.html` (register section only) · `style.css` (reg-* rules only) · `script.js` (add blur validation + error-summary helper; existing functions unchanged)

---

## Pre-flight: what exists today

| Item | Current state | Target state |
|------|--------------|--------------|
| `.reg-split-screen` grid | `28% minmax(0,1fr)` (line 695) | `minmax(320px, 36%) minmax(0, 1fr)` |
| `.reg-left-panel h1` | `clamp(1rem,1.8vw,1.4rem)` — tiny (line 696) | `clamp(2.75rem,4.6vw,4.75rem)`, weight 800 |
| Left panel padding-top | inherits `.left-panel` `clamp(3rem,7vw,6rem)` | `40px` on desktop |
| Right panel padding-top | inherits `.right-panel` `clamp(3rem,7vw,6rem)` | `32px` |
| `.reg-form-container` padding | `28px 0 24px` (line 705) | `32px 0 24px` |
| `.back-button` margin-bottom | `var(--space-4)` = 16px | `24px` |
| `.reg-form-container .eyebrow` margin-bottom | `var(--space-1)` = 4px | `6px` |
| `.reg-form-container h1` margin-bottom | `6px` | `4px` |
| `.reg-form-container .form-subtitle` margin-bottom | `16px` | `20px` |
| `.reg-group-label` margin | `14px 0 6px` (line 715) | `28px 0 12px` |
| `.reg-group-label:first-of-type` margin-top | `0` (line 722) | keep `0` |
| `.reg-hint` font-size | `var(--text-12)` = 12px (line 770) | `13px` (var(--text-13)) |
| `reg-proof-chips` margin | `12px 0 14px` (line 785) | keep |
| `.reg-dropzone` height | `min-height: 120px` (line 789) | `min-height: 130px` |
| `.register-consent input` size | `18px×18px` (line 870) | `20px×20px` |
| `capture` on file input | absent | `capture="environment"` |
| Blur validation | absent | add per-field |
| `reg-field-error` spans | absent from HTML + CSS | add both |
| `reg-error-summary` | absent | add |
| CSS cache-bust version | `?v=2026101026` | `?v=2026101027` |

---

## Step 1 — CSS: left brand panel (desktop)

**What:** Override the `.reg-left-panel` and `.reg-split-screen` rules so the panel
is 35-38% wide, the headline is large and bold, and content is anchored from the top
with 40 px of padding.

**Files:** `style.css`

**Exact changes (lines ~693–696, add new rules below):**

Replace line 695:
```css
/* was: .reg-split-screen { grid-template-columns: 28% minmax(0, 1fr); } */
#register.auth-section .split-screen,
.reg-split-screen { grid-template-columns: minmax(320px, 36%) minmax(0, 1fr); }
```

Replace line 696 and append desktop overrides:
```css
/* Left panel — large headline, top-anchored */
.reg-left-panel { padding-top: 40px; }
.reg-left-panel .panel-content { align-items: flex-start; }
.reg-left-panel h1 {
    font-size: clamp(2.75rem, 4.6vw, 4.75rem);
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.04em;
    color: var(--color-ink);
    margin-bottom: var(--space-4);
}
/* Left padding: 100px desktop, 40px tablet */
@media (min-width: 1024px) {
    .reg-left-panel { padding-left: 100px; }
}
@media (min-width: 768px) and (max-width: 1023px) {
    .reg-left-panel { padding-left: 40px; }
}
```

Note: The `::after` decorative circle, seal (`.panel-logo`), `.badge`, subtext `<p>`,
and `.panel-footer` inside `.reg-left-panel` are unchanged — only the h1 size and
panel padding change.

**Verify:** Open `http://localhost/barangay_portal_new/#register` at 1280 px wide.
The left panel headline ("Your account lets you…") must be visibly large (≈ 44–76 px)
and bold, left-aligned, starting near the top of the panel.

---

## Step 2 — CSS: top spacing and form header

**What:** Reduce the top padding on both panels and tighten the header spacing above
the form fields.

**Files:** `style.css`

**Exact changes:**

```css
/* Right panel: start higher */
.reg-right-panel { overflow-y: auto; padding-top: 32px; }

/* Back button */
.reg-form-container .back-button { margin-bottom: 24px; }

/* Eyebrow above "Create your account" */
.reg-form-container .eyebrow { margin-bottom: 6px; }

/* H1 "Create your account" */
.reg-form-container h1 { margin-bottom: 4px; font-size: clamp(1.4rem, 2.2vw, 1.9rem); }

/* Subtitle + required note */
.reg-form-container .form-subtitle { margin-bottom: 20px; font-size: var(--text-13); color: var(--color-muted); }
```

These replace the existing single-line rules at lines 699, 707, 708, 709, 710.

**Verify:** At 1280×1080 the "Back to home" button and the "RESIDENT ENROLLMENT"
eyebrow are visible near the top of the right panel without excessive whitespace.

---

## Step 3 — CSS: form grid and field rhythm

**What:** Set the section eyebrow spacing to 28 px above / 12 px below, fix the hint
font-size to 13 px, and ensure all inputs stay at 48 px.

**Files:** `style.css`

**Exact changes:**

```css
/* Section eyebrow labels */
.reg-group-label {
    margin: 28px 0 12px;          /* was 14px 0 6px */
    color: var(--color-accent);
    font-size: var(--text-10);
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
}
.reg-group-label:first-of-type { margin-top: 0; }  /* keep */

/* Helper text: bump to 13px, keep color for 4.5:1 contrast */
.reg-hint { margin-top: 6px; font-size: var(--text-13); color: var(--color-text); line-height: 1.5; }
```

These update the block at lines 714–721 and line 770. No other field rules change
(inputs are already 48 px, label→input gap is already 6 px via `margin-bottom: 6px`
on `.reg-field label`).

**Verify:** Inspect a field (e.g. First name) in DevTools — label margin-bottom = 6 px,
input height = 48 px, hint margin-top = 6 px, hint font-size = 13 px.

---

## Step 4 — CSS: proof of residency section

**What:** The chips and dropzone already look close to spec; these are minor tweaks —
bump dropzone min-height to 130 px and ensure the duplicate `.reg-dropzone` block
(lines 828–841) does not contradict the override at line 789.

**Files:** `style.css`

**Exact changes:**

Line 789 override:
```css
/* Drop zone — shorter, higher-contrast border */
.reg-dropzone { padding: 20px var(--space-4) !important; min-height: 130px; border: 2px dashed #78716c !important; }
```
(Change `120px` → `130px`; border color `#78716c` already provides > 3:1 on white.)

The `!important` flags on line 789 already override the base `.reg-dropzone` block
(lines 828–841). No changes needed in that base block.

Chips (line 785–786) and privacy note (lines 806–807) are already correct. No change.

**Verify:** Dropzone is between 120–140 px tall. Dashed border is `#78716c` (dark warm
gray). Chips wrap in a single row at full width.

---

## Step 5 — CSS: account section

**What:** Size the consent checkbox to 20 px. The password grid and submit button
rules (lines 810–822) are already correct (`.reg-grid-pw`, `.reg-pw-field`,
`.reg-submit-btn { width:100%; height:48px; margin-top:18px; }`).

**Files:** `style.css`

**Exact changes:**

```css
/* Consent checkbox */
.register-consent input { width: 20px; height: 20px; flex-shrink: 0; margin-top: 1px; accent-color: var(--color-accent); }
```
Replace line 870 (currently `width:18px; height:18px; margin-top:2px`).

Also resolve the duplicate `.register-consent` block (lines 816–818 and lines 866–871)
— these are identical except for checkbox size. Keep only the block at lines 815–818
with the 20 px checkbox; delete the duplicate at lines 865–871 to avoid confusion.

**Verify:** Checkbox in the consent row is visually 20 px and vertically centered with
the label text.

---

## Step 6 — CSS: inline validation styles (new rules)

**What:** Add `.reg-field-error` and `.reg-error-summary` styles. These classes do not
exist yet in `style.css`.

**Files:** `style.css` — append after the existing `/* Log in link */` block (~line 875)
and before the `@media (max-width: 1023px)` block (~line 877).

```css
/* Inline field errors */
.reg-field-error {
    display: block;
    margin-top: 4px;
    color: var(--color-danger);
    font-size: var(--text-12);
    line-height: 1.4;
    min-height: 1.2em;   /* reserve space to prevent layout shift */
}
.reg-field-error:empty { display: none; }

/* Error input state */
.reg-field input[aria-invalid="true"],
.reg-field select[aria-invalid="true"] {
    border-color: var(--color-danger);
    box-shadow: 0 0 0 3px rgba(185,28,28,.12);
}

/* Error summary above submit */
.reg-error-summary {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: var(--space-3) var(--space-4);
    margin-bottom: 12px;
    border: 1px solid var(--color-danger-border);
    border-radius: var(--radius-control);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: var(--text-13);
    font-weight: 600;
}
.reg-error-summary:empty { display: none; }
```

**Verify:** DevTools shows `.reg-field-error` and `.reg-error-summary` styles loaded.
No visual change until validation fires.

---

## Step 7 — HTML: add error spans to register form fields

**What:** Insert `<span class="reg-field-error" id="{fieldId}-error" role="alert"></span>`
immediately after each field's input (or hint span when one exists). Also add
`capture="environment"` to the file input and insert the error summary `<div>` just
before the submit button.

**File:** `index.html` — `#register` section only (~lines 315–407).

### 7a. Personal details fields

After `<input type="text" id="register-first-name" …>` add:
```html
<span class="reg-field-error" id="register-first-name-error" role="alert"></span>
```

After `<input type="text" id="register-last-name" …>` add:
```html
<span class="reg-field-error" id="register-last-name-error" role="alert"></span>
```

Middle name and suffix get no error span (optional fields).

### 7b. Contact fields

After `<input type="email" id="register-email" …>` add:
```html
<span class="reg-field-error" id="register-email-error" role="alert"></span>
```

### 7c. Address field

After the existing `<span class="reg-hint">e.g. 12 Rizal St. …</span>` add:
```html
<span class="reg-field-error" id="register-address-error" role="alert"></span>
```

### 7d. Proof of residency

Add `capture="environment"` to the file input (currently line ~360):
```html
<input type="file" id="register-proof" name="proof" accept=".pdf,.docx,.jpg,.jpeg,.png"
       capture="environment" required class="reg-dropzone-input">
```

After `.reg-proof-privacy` paragraph add:
```html
<span class="reg-field-error" id="register-proof-error" role="alert"></span>
```

### 7e. Password fields

After the password `<div class="password-field reg-pw-field">…</div>` wrapper, after
the existing `<span class="reg-hint">At least 8 characters…</span>` add:
```html
<span class="reg-field-error" id="register-password-error" role="alert"></span>
```

After the confirm-password `<div class="password-field reg-pw-field">…</div>` wrapper,
after the existing `<span class="reg-hint reg-hint-spacer" aria-hidden="true">&nbsp;</span>` add:
```html
<span class="reg-field-error" id="register-confirm-password-error" role="alert"></span>
```

### 7f. Error summary before submit

Just before `<button type="submit" class="btn-solid reg-submit-btn">Submit registration</button>` insert:
```html
<div class="reg-error-summary" id="reg-error-summary" role="alert" aria-live="assertive"></div>
```

**Verify:** Inspect DOM — each required field has a sibling `<span role="alert">` with
the correct id. File input has `capture="environment"`. Error summary div is present
before the submit button.

---

## Step 8 — JS: blur validation and error-summary

**What:** Add a self-contained `regInitValidation()` function called from `showSection`
(where `regInitDropzone` is already called). This function attaches blur listeners to
the seven required fields and a `change` listener to the file input. It must NOT alter
`handleRegister()`, `regInitDropzone()`, `regShowPreview()`, or `regClearFile()`.

**File:** `script.js`

### 8a. Add helper functions (insert after `regClearFile`, before `regInitStep1`, ~line 767)

```js
/* ============================================================
   REGISTER — inline validation
   ============================================================ */

/** Set or clear an error on a register field. */
function regSetError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const errorEl = document.getElementById(fieldId + '-error');
    if (!field || !errorEl) return;
    if (message) {
        errorEl.textContent = message;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', fieldId + '-error');
    } else {
        errorEl.textContent = '';
        field.removeAttribute('aria-invalid');
    }
}

/** Validate a single field; returns error string or '' */
function regValidateField(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return '';
    const val = field.value.trim();

    if (fieldId === 'register-first-name' || fieldId === 'register-last-name' || fieldId === 'register-address') {
        if (!val) return 'This field is required.';
    }
    if (fieldId === 'register-email') {
        if (!val) return 'Email address is required.';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) return 'Enter a valid email address.';
    }
    if (fieldId === 'register-password') {
        if (!val) return 'Password is required.';
        if (val.length < 8) return 'Password must be at least 8 characters.';
    }
    if (fieldId === 'register-confirm-password') {
        const pw = document.getElementById('register-password');
        if (!val) return 'Please confirm your password.';
        if (pw && val !== pw.value) return 'Passwords do not match.';
    }
    return '';
}

function regInitValidation() {
    const fieldIds = [
        'register-first-name',
        'register-last-name',
        'register-email',
        'register-password',
        'register-confirm-password',
        'register-address',
    ];
    fieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', () => regSetError(id, regValidateField(id)));
        el.addEventListener('input', () => {
            if (el.getAttribute('aria-invalid') === 'true') regSetError(id, regValidateField(id));
        });
    });

    // File input: validate on change
    const proofInput = document.getElementById('register-proof');
    if (proofInput) {
        proofInput.addEventListener('change', () => {
            const errorEl = document.getElementById('register-proof-error');
            if (!errorEl) return;
            const file = proofInput.files?.[0];
            if (!file) {
                errorEl.textContent = 'Please upload proof of residency.';
                return;
            }
            const maxBytes = 5 * 1024 * 1024;
            const allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
            if (file.size > maxBytes) {
                errorEl.textContent = 'File exceeds 5 MB. Please upload a smaller file.';
                proofInput.value = '';
                regClearFile();
                return;
            }
            if (!allowed.includes(file.type) && !file.name.match(/\.(pdf|docx|jpe?g|png)$/i)) {
                errorEl.textContent = 'Invalid file type. Use PDF, DOCX, JPG, or PNG.';
                proofInput.value = '';
                regClearFile();
                return;
            }
            errorEl.textContent = '';
        });
    }
}
```

### 8b. Hook `regInitValidation` into `showSection` (line ~146)

In `showSection()`, on the line that already reads:
```js
if (id === 'register') setTimeout(regInitDropzone, 50);
```
Change to:
```js
if (id === 'register') { setTimeout(regInitDropzone, 50); setTimeout(regInitValidation, 60); }
```

### 8c. Add submit-time full-form validation inside `handleRegister` (lines ~822–831)

At the top of `handleRegister`, after `e.preventDefault()` and before the password
match check, insert a full-form sweep that populates the error summary and focuses
the first invalid field. Insert this block (do NOT remove or alter any existing code):

```js
// Full-form validation sweep
const fieldIds = ['register-first-name','register-last-name','register-email',
                  'register-password','register-confirm-password','register-address'];
let firstInvalidEl = null;
fieldIds.forEach(id => {
    const msg = regValidateField(id);
    regSetError(id, msg);
    if (msg && !firstInvalidEl) firstInvalidEl = document.getElementById(id);
});
const proofInput2 = document.getElementById('register-proof');
const proofErr = document.getElementById('register-proof-error');
if (proofErr && (!proofInput2 || !proofInput2.files?.length)) {
    proofErr.textContent = 'Please upload proof of residency.';
    if (!firstInvalidEl) firstInvalidEl = proofInput2;
}
const summary = document.getElementById('reg-error-summary');
if (firstInvalidEl) {
    if (summary) summary.textContent = 'Please fix the highlighted fields before submitting.';
    firstInvalidEl.focus();
    return;
}
if (summary) summary.textContent = '';
```

Insert this block at line ~824, immediately after `e.preventDefault();`.

**Verify:**
- Blur on empty First name → red border + "This field is required." below field.
- Password shorter than 8 chars → error.
- Confirm password mismatch → error.
- File > 5 MB → "File exceeds 5 MB." error, zone resets.
- Submit empty form → error summary text appears, focus moves to First name.
- Valid form submission → `handleRegister` proceeds to `fetch('api/register.php', …)` normally.

---

## Step 9 — CSS: responsive (≤1023px) — verify existing rules are sufficient

**What:** Check that the existing `@media (max-width: 1023px)` block (lines 877–897)
already covers the required responsive behavior. It does cover most cases, but two
gaps remain.

**Files:** `style.css` — inside or after the existing `@media (max-width: 1023px)` block.

**Gaps to fill:**

1. Left panel on mobile should use `padding: 20px 24px` not `var(--space-4) var(--space-5-5)`
   (line 882 currently). Update:
   ```css
   .reg-left-panel { padding: 20px 24px !important; }
   ```

2. The `.reg-form-container` responsive rule (line 892) already reads
   `width: min(100% - 40px, 600px); padding: 20px 0;` — correct, no change needed.

3. Confirm the chips wrap correctly: `.reg-proof-chips` already has `flex-wrap: wrap`
   (line 785) — no change needed.

4. Password + confirm stack: `.reg-grid-pw .reg-col-6 { grid-column: span 12; }` 
   already on line 896 — correct.

**Verify:** At 390 px wide (iPhone viewport): left panel collapses to compact banner
row (seal + badge only), all form fields are single-column, chips wrap naturally,
Password and Confirm password stack.

---

## Step 10 — HTML + CSS: cache-bust version bump

**What:** Update the stylesheet `<link>` cache-bust query string.

**File:** `index.html` line 10.

Change:
```html
<link rel="stylesheet" href="style.css?v=2026101026">
```
To:
```html
<link rel="stylesheet" href="style.css?v=2026101027">
```

**Verify:** Browser network tab shows `style.css?v=2026101027` loading without
serving from cache.

---

## Execution order and dependency notes

Items 1–6 are CSS-only and independent of each other — all can be applied in a
single CSS editing pass. Items 7 (HTML) depends on nothing. Item 8 (JS) depends
on items 6 and 7 (needs the error span IDs to exist in DOM and the CSS classes
defined). Item 9 is a CSS adjustment pass. Item 10 is a one-liner that should be
last (after all CSS edits are final) so the cache is busted once.

Recommended apply order: **1 → 2 → 3 → 4 → 5 → 6 → 9** (single CSS pass) →
**7** (HTML) → **8** (JS) → **10** (cache bump).

---

## Full verification checklist

Run against `http://localhost/barangay_portal_new/#register`:

| Test | Expected |
|------|----------|
| 1280×1080 — left panel headline | Large bold text, ~44–76 px, left-aligned, near top |
| 1280×1080 — form fits viewport | Entire form (through login link) visible without scrolling |
| 1280×1080 — section eyebrows | 28 px above, 12 px below |
| 1280×1080 — inputs | 48 px tall everywhere |
| 1280×1080 — chips | Single wrapping row, not bullet list |
| 1280×1080 — dropzone | 130 px tall, `#78716c` dashed border, centered icon + text |
| 1280×1080 — password row | Two equal columns side by side |
| 1280×1080 — consent checkbox | 20 px, vertically centered with label |
| 390×844 — layout | Single column, compact brand banner, chips wrap |
| 390×844 — password fields | Stack vertically |
| Blur: empty First name | Red border + "This field is required." |
| Blur: invalid email | "Enter a valid email address." |
| Blur: password < 8 chars | "Password must be at least 8 characters." |
| Blur: confirm ≠ password | "Passwords do not match." |
| File > 5 MB | Inline "File exceeds 5 MB.", zone resets |
| Submit empty | Error summary appears, focus on First name |
| Valid submit | `handleRegister` hits `api/register.php` normally |
| `network` tab | `style.css?v=2026101027` |
