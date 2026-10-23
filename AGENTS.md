# COMMERCIAL FLAGSHIP TEMPLATE — STRICT GLOBAL RULES
*(Universal Standard for Shopify Theme Store, Framer Marketplace, Next.js, WordPress & HTML Commercial Templates)*

## CORE DIRECTIVE

Every rule in this document is **MANDATORY, NON-NEGOTIABLE, AND ENFORCEABLE**.

These are binding project requirements, NOT suggestions, preferences, recommendations, or optional best practices.

- **MUST** means mandatory.
- **MUST NOT** means strictly prohibited.
- **NEVER** indicates a forbidden practice.
- No rule may be ignored, weakened, or silently bypassed.
- No requirement may be sacrificed merely to simplify implementation.
- Satisfying one rule does not excuse violating another.
- A task or phase MUST NOT be declared complete while any applicable rule remains unsatisfied.
- If two requirements appear to conflict, find a solution that satisfies both. If that is genuinely impossible, report the conflict instead of silently compromising.

**The objective is to deliver exceptional, original, fully functional, genuinely customizable commercial flagship templates that pass the strictest marketplace design & technical reviews (including Shopify Theme Store Initial Design Triage and Framer Marketplace Review) on the first submission.**

---

# PART I — VISUAL DESIGN, ART DIRECTION & DESIGN JUDGMENT

## RULE 01 — VISUAL EXCELLENCE & DESIGN JUDGMENT ABOVE CONVENIENCE

Now that a merely "polished" result is easy to produce with starter themes and generation tools, **polish on its own no longer sets a template apart**. Passing linters, theme checks, and accessibility requirements is expected of every submission; a template can be well-built, accessible, and polished and STILL be rejected at initial review.

What sets a flagship template apart is **strong design judgment**: choices that are deliberate and consistent, a clear point of view, and knowing what to leave out as well as what to include. **The templates that stand out feel designed, not assembled.**

Every page and template MUST demonstrate outstanding design judgment through:

- Original art direction with a clear, unmistakable point of view.
- Distinctive visual composition and spatial architecture.
- Sophisticated typography and deliberate hierarchy.
- Intentional whitespace, pacing, and visual rhythm.
- Exceptional imagery and media presentation that still holds up when replaced with ordinary customer photos.
- Carefully considered color relationships.
- Refined, purposeful interactions and motion.
- Coherent brand identity that supports merchant/buyer expression.
- Exceptional attention to micro-details across every route, drawer, and state.

NEVER choose a visually inferior or conventional solution simply because it is faster, easier, safer, or more convenient to implement.

NEVER simplify an ambitious design merely to avoid implementation complexity.

## RULE 02 — ABSOLUTELY NO GENERIC AI-GENERATED OR STARTER-THEME AESTHETICS

The template MUST NOT resemble a stereotypical AI-generated, vibe-coded, starter-derived (e.g., Dawn/Horizon reskin, uncustomized shadcn), or mass-produced template.

The following are STRICTLY PROHIBITED as unconsidered default design patterns:

- Predictable centered hero sections with generic headings and two CTA buttons.
- Repetitive `Hero → Cards → Features → Testimonials → CTA` structures.
- Endless grids of identical rounded cards.
- Generic SaaS layouts reused for ecommerce, editorial, or physical-product industries.
- Arbitrary gradients, glowing backgrounds, or unmotivated glassmorphism.
- Overused pill-shaped badges and decorative floating elements.
- Excessively uniform section spacing (e.g., mechanical vertical stacking where every section has identical padding).
- Generic typography lacking character or structural contrast.
- Repetitive alternating 50/50 left-right content sections.
- Unoriginal navigation, product cards, collection grids, cart drawers, and footer structures.
- Generic component-library or reference-theme appearances with minimal customization.
- Superficial cosmetic changes (swapping fonts, colors, or border radii) on top of existing templates or free themes.

Individual patterns are permitted only when they are deliberately chosen and genuinely support the visual concept. Originality MUST come from architecture, composition, art direction, hierarchy, typography, and interaction design — not from superficial decorative effects.

## RULE 03 — RECOGNIZABLE WITHOUT THE LOGO AND WITHOUT DEMO CONTENT

Every flagship template MUST pass the **Blind Brand & Demo-Swap Recognition Test**.

Imagine removing:

- The logo, brand name, wordmark, and favicon.
- The curated demo color palette.
- **All art-directed editorial/lifestyle demo photography** (replacing them with plain studio product shots and average customer copy).

The remaining template MUST still communicate:

1. Its intended industry, merchant archetype, and purpose.
2. A distinctive brand personality and clear point of view.
3. A recognizable and coherent **structural signature** (grid ratios, border/divider choreography, typographic pairing, index/metadata layout, card anatomy, and interactive mechanics).
4. A design character that differentiates it from free themes and generic competitors.

**A strong template identity must exist in its structural architecture — independently of its logo and demo photography.**

## RULE 04 — NO SAFE OR CONVENTIONAL DESIGN BY DEFAULT

NEVER automatically select the safest or most conventional visual solution.

NEVER prioritize familiarity over meaningful originality.

Every major design decision MUST demonstrate intentional creative direction:

- Unexpected but purposeful compositions.
- Distinctive editorial and merchandising layouts.
- Sophisticated asymmetry where appropriate.
- Intentional typography contrasts.
- Strong visual storytelling and page-level choreography.
- Creative use of imagery, framing, and negative space.
- Memorable section transitions and restraint (knowing what to leave out).
- Recognizable visual motifs and industry-specific functional elements.

Creativity MUST remain controlled, functional, and commercially appropriate. Unnecessary complexity, poor readability, chaotic layouts, and novelty without commercial purpose are NOT acceptable.

**Be distinctive without sacrificing usability or conversion clarity.**

## RULE 05 — ART DIRECTION & DIFFERENTIATION BEFORE IMPLEMENTATION

A coherent visual direction and differentiation thesis MUST be established before constructing layouts or writing code.

Before building, explicitly define:

- Target merchant/buyer persona and specific industry problem solved.
- How the template diverges structurally from free/default themes in the same category.
- Visual identity and one or two signature visual/interactive motifs.
- Typography system and scale.
- Color system and surface hierarchy.
- Grid, proportion, and composition principles.
- Image, aspect-ratio, and media direction.
- Component anatomy (headers, cards, buttons, inputs, drawers, badges).
- Motion and micro-interaction language.

DO NOT begin by assembling generic UI sections and attempting to make them look premium afterward.

## RULE 06 — CONSISTENCY ACROSS EVERY TEMPLATE WITHOUT MECHANICAL REPETITION

Maintain a unified design identity across the entire product while allowing meaningful variation between pages and sections.

**ZERO HOMEPAGE BIAS:** The design quality MUST be identical across **every** template and surface — including Product Detail (PDP), Collection/Catalog, Cart, Search, Filter Drawers, Customer Account, Blog/Editorial, About, Contact, FAQ, 404, and Mobile — not just the Homepage.

MUST:

- Preserve consistent typography, spacing tokens, and surface treatments across all routes.
- Maintain recognizable component styling and interaction quality on inner pages.
- Establish coherent layout rhythm with varied density and pacing.
- Adapt compositions to the specific job of each page.

MUST NOT:

- Spend 80% of creative effort on the homepage and fall back to default layouts on Product, Collection, Cart, or Search pages.
- Make every page or section follow the exact same layout structure.
- Force all content into the same card patterns.

---

# PART II — PLATFORM-NATIVE ARCHITECTURE (AUTO-ADAPTIVE BY STACK)

## RULE 07 — STRICT PLATFORM-NATIVE IMPLEMENTATION

Every template MUST be built using the idiomatic, native architecture of its target platform so that buyers/merchants get genuine platform performance and native visual editor compatibility.

### 7A. When Building for FRAMER
- **Native First, Code Only When Genuinely Necessary:** Prioritize Native Frames, Stacks, Grids, Native Text/Image elements, Native Components, Variants, Variables, Property Controls, Shared Styles, Native CMS, and Native Interactions/Animations.
- **No Code Component Abuse:** Full-page Code Components, large code-driven page sections, React headers/footers, or TSX product cards are STRICTLY PROHIBITED when a native solution exists. Permitted Code Components must be narrowly scoped, technically justified, and expose full Property Controls.

### 7B. When Building for SHOPIFY THEME STORE (Liquid OS 2.0)
- **100% Greenfield or Official Skeleton Base Only:** NEVER derive from, fork, copy, or reskin **Dawn**, **Horizon**, or any existing reference/commercial theme. Zero Dawn class signatures (`.gradient`, `.isolate`, `color-background-1`) permitted.
- **Native Online Store 2.0 Architecture:** Use Liquid, OS 2.0 JSON templates (`templates/*.json`), modular Sections & Blocks (`max_blocks`, `presets`), native CSS with custom properties, and vanilla progressive JavaScript / Web Components.
- **Complete 16-Template Coverage:** Must implement `layout/theme.liquid`, all 12 mandatory JSON templates (`index`, `product`, `collection`, `list-collections`, `cart`, `blog`, `article`, `page`, `search`, `404`, `password`, `gift_card.liquid`), plus customer account support, `settings_schema.json`, and `settings_data.json`. Zero forbidden files (`config/markets.json`, `robots.txt.liquid`, `.scss`).
- **Theme Editor Dynamic Lifecycle:** All interactive scripts and Web Components MUST remain functional across `shopify:section:load`, `shopify:section:unload`, `shopify:section:select`, and `shopify:section:reorder` without requiring a full page refresh.
- **Native Font & Color Pickers:** Never bundle custom webfont files (`.woff2`/`.ttf`) in `assets/`; strictly use Shopify's `font_picker` setting and `font_modify` / `font_url` filters. Use native `color` / `color_scheme` settings with paired foreground/background contrast compliance.
- **Strict Shopify Schema Terminology & American English:** All section names, preset names, and setting labels MUST use **sentence case**, **American English** (`color`, `center`, `gray`, `catalog`, `canceled`, `customize`), **no ampersands (`&`)**, and exact Shopify terms (`home page`, `top bar`, `bottom bar`, `slideshow`, `subheading`, `button label`, `cart type`).

### 7C. When Building for NEXT.JS / REACT
- **Modern App Router & Clean Boundaries:** Use Server Components by default; isolate `"use client"` to interactive leaf components.
- **Token-Driven Design System:** Define custom typography, color tokens, radii, and spacing scales in CSS variables / Tailwind config. Never ship uncustomized shadcn/ui or Radix defaults.
- **Data & Config Separation:** Keep structured content, navigation, and product/catalog schemas cleanly separated from presentation components so buyers can swap data sources or CMS providers effortlessly.

### 7D. When Building for WORDPRESS
- **Native Block / FSE or Clean Theme Hierarchy:** Leverage `theme.json` design tokens, native Block Patterns, Template Parts, and custom Gutenberg blocks or clean PHP template hierarchy without bloated page-builder lock-in unless explicitly requested.

### 7E. When Building for HTML / CSS / VANILLA JS
- **Semantic, Zero-Bloat Architecture:** Use semantic HTML5 landmarks, a disciplined CSS Custom Properties token architecture (typography, spacing, color, elevation), and modular, dependency-free vanilla ES6+ JavaScript.

## RULE 08 — GENUINE CUSTOMER / MERCHANT EDITABILITY

The final template MUST be genuinely customizable by its target buyer without breaking the layout:

- **For No-Code / Merchant Platforms (Shopify, Framer, WordPress):** Non-technical merchants MUST be able to edit text, images, colors, typography, navigation, CMS/catalog records, section order, and component variants directly through the visual editor (Shopify Theme Editor / Framer Canvas / WP Editor) without touching code.
- **For Developer Templates (Next.js, HTML):** Developers MUST be able to customize theme tokens, content collections, and layout props from centralized config/data files and well-typed component props without hunting through hardcoded JSX/HTML strings.

## RULE 09 — REUSABLE, MAINTAINABLE COMPONENT ARCHITECTURE

Required across all platforms:

- Shared, reusable components/snippets/sections with meaningful variants.
- Centralized typography, color, spacing, and surface tokens.
- Consistent, self-documenting naming conventions.
- Clean DOM / layer organization with minimal nesting and zero unnecessary wrappers.
- Consistent component behavior and proportions across all breakpoints.

## RULE 10 — TECHNICAL DISCIPLINE WITHOUT VISUAL COMPROMISE

Platform constraints (such as Framer Native-First or Shopify's `font_picker` and Liquid section architecture) MUST NEVER become an excuse for mediocre, generic design.

When an ambitious visual idea meets platform constraints:
1. Investigate native platform capabilities deeply (e.g., CSS Grid choreography, Subgrid, sticky scroll, clip-path, Web Components, native Framer variants).
2. Engineer a solution that preserves the full art-directed experience while remaining 100% platform-compliant.
3. NEVER downgrade a distinctive concept into a generic layout for convenience.

---

# PART III — FUNCTIONAL QUALITY, CMS/DATA & REAL COMMERCE EXPERIENCE

## RULE 11 — NO FAKE FUNCTIONALITY OR DECEPTIVE PATTERNS

Every visible interactive element MUST perform its declared purpose.

STRICTLY PROHIBITED:

- Dead buttons, `#` dummy links that go nowhere, or broken navigation.
- Fake search bars or nonfunctional category/tag/attribute filters presented as working UI.
- Cart buttons, variant selectors, or quantity steppers that do nothing.
- Broken tabs, accordions, drawers, or modals.
- Deceptive urgency patterns (countdown timers that reset on reload, fake "24 people viewing this", fabricated sales popups).
- Placeholder integrations claimed as complete.

## RULE 12 — STRUCTURED CMS & CATALOG ARCHITECTURE

Repeatable content (products, collections, journal articles, case studies, team members, FAQs, lookbooks) MUST be driven by structured CMS/catalog data rather than copy-pasted static markup:

- Realistic, domain-authentic sample records (zero "Lorem ipsum", zero "Product 1 / Product 2").
- Clean relational linking (e.g., related products, article categories, collection filtering, product-to-ritual/lookbook pairing).
- Stress-tested against variable data lengths and missing optional fields.

## RULE 13 — REAL, HIGH-CRAFT COMMERCE EXPERIENCE

For ecommerce templates (Shopify, Framer Commerce, Next.js Commerce, WooCommerce):

- **Product Discovery:** Collection/catalog pages must provide working, well-designed filtering, sorting, and scannable metadata (not just title + price).
- **Product Detail Presentation (PDP):** Must include purposeful gallery choreography, clear variant/option selection, unit pricing / stock / pickup states (where required by platform), structured specs/ingredients/shipping disclosures, and cohesive cross-sell pairing.
- **Cart & Checkout Flow:** Cart drawer and cart page must handle quantity updates, empty cart state, order notes, free-shipping progress (if applicable), and subtotal transparency cleanly.

## RULE 14 — PURPOSE-BUILT EDITORIAL & STORYTELLING PAGES

Blog, Journal, Lookbook, and Brand Story pages MUST receive the same creative and typographic attention as commercial pages:

- Distinctive article listing and featured-story compositions (never just a uniform 3-column card grid).
- Carefully calibrated long-form reading measure (`60ch–72ch`), drop caps, pull quotes, figure captions, and footnotes/side-notes.
- Seamless commerce-in-editorial integration (e.g., shoppable editorial callouts or inline product references) where appropriate.

---

# PART IV — RESPONSIVE, MOTION, CONTENT & TECHNICAL STANDARDS

## RULE 15 — FLAGSHIP-LEVEL RESPONSIVE CHOREOGRAPHY

Desktop, tablet, and mobile MUST all achieve flagship visual quality:

- Mobile MUST NOT be desktop columns passively stacked into a single endless vertical tube.
- Re-choreograph spacing, typographic scale, image crops, sticky buy bars, filter/navigation drawers, and horizontal scroll/grid density specifically for touch viewports (`375px`, `390px`, `768px`, `1024px`, `1440px+`).
- Zero horizontal overflow, zero clipped text, and minimum `44x44px` touch targets where required.

## RULE 16 — PURPOSEFUL, HARDWARE-ACCELERATED MOTION

Motion MUST be intentional, cohesive, and tied to brand character:

- Animate only transform and opacity where possible for 60fps performance.
- Use motion to communicate state change, spatial continuity, drawer/modal hierarchy, and tactile hover feedback.
- Respect `prefers-reduced-motion: reduce`.
- Avoid generic "fade-up on every single block" or distracting scroll-jacking.

## RULE 17 — DOMAIN-AUTHENTIC CONTENT & IMAGERY

Content and imagery MUST reinforce the intended brand identity:

- Cohesive photographic direction with consistent lighting, color grading, and framing.
- Specific, credible copy written in the vocabulary of the target industry (zero AI filler phrases).
- 100% commercial-use-safe imagery (e.g., Shopify Burst, licensed Unsplash, or custom-generated studio assets with documented provenance).

## RULE 18 — ACCESSIBILITY, PERFORMANCE & SEO

Every completed page MUST satisfy commercial marketplace benchmarks (including Shopify Theme Store's Lighthouse >= 90 Accessibility and high Performance standards):

- Semantic HTML landmarks (`header`, `main`, `nav`, `section`, `article`, `footer`) and strict `h1 → h2 → h3` heading hierarchy.
- Full keyboard navigation (`Tab`, `Escape` to close drawers/modals, focus trapping in overlays, visible `:focus-visible` states).
- Accessible names (`aria-label`, `aria-expanded`, `aria-controls`, associated `<label>` elements) on all icon buttons and inputs.
- WCAG 2.2 AA color contrast (`4.5:1` normal text, `3:1` large text and UI components).
- Proper image optimization (`loading="lazy"` below the fold, `fetchpriority="high"` on LCP hero image, explicit `width`/`height` or `aspect-ratio` to prevent CLS).

---

# PART V — EXECUTION DISCIPLINE

## RULE 19 — STRICT INSTRUCTION ADHERENCE & REFACTORING INTEGRITY

- Follow explicit project constraints, design tokens, and architecture rules without silent shortcuts.
- When refactoring or converting code, preserve 100% of visual fidelity, layout proportions, typography, interactions, and responsive behavior.
- Never replace a sophisticated visual concept with a basic layout to finish faster.

## RULE 20 — HONEST VERIFICATION

Quality gates MUST be based on actual rendered inspection and test execution:

- **PASS:** Executed, inspected, and verified against requirements.
- **FAIL:** Executed and failed to meet requirements.
- **NOT VERIFIED / NOT TESTED:** Not yet executed or lacks testing environment.
- **NOT APPLICABLE:** Genuinely does not apply to the current stack.

NEVER mark an untested requirement as `PASS`.

---

# PART VI — THE 6 MARKETPLACE DESIGN & UX REVIEW GATES
*(Specifically engineered to prevent Shopify Theme Store "Initial Design Check" rejections and Framer Marketplace rejections)*

Before any template or major page is declared complete, it MUST pass all six questions asked by marketplace design reviewers:

## GATE 1 — MERCHANT / BUYER VALUE (BEYOND GENERIC SECTIONS)
> *"Does the theme meet a real need for a specific type of merchant, and give them something meaningful the store doesn't already offer?"*

- **Prohibited:** Relying solely on standard multipurpose sections (Hero + Product Grid + Rich Text + Testimonials + Newsletter) dressed up with nice colors and fonts.
- **Required:** Purpose-built, niche-specific UX and merchandising patterns tailored to the target industry (e.g., ingredient/formulation breakdowns, ritual or regimen builders, technical spec matrices, tactile material comparisons, interactive editorial lookbooks, scale/fit guides, provenance timelines, or sensory notes).
- Every custom section or pattern MUST justify its existence by answering: *"What specific merchant or buyer problem does this solve that a default free theme cannot?"*

## GATE 2 — RECOGNIZABLE WITHOUT LOGO AND DEMO CONTENT (THE PLAIN-PHOTO TEST)
> *"If you removed the logo and demo content, would the theme still be recognizable as yours?"*

- **The Demo-Swap Test:** Imagine removing the brand logo, replacing the color palette, AND replacing all art-directed lifestyle photos with ordinary, plain studio product photos and average-length merchant text.
- **Requirement:** The template MUST still be immediately recognizable as an original design through its **structural signature**:
  - Distinctive grid architecture and proportion ratios.
  - Signature typographic hierarchy, metadata pairing, and index/numbering systems.
  - Intentional border, divider, surface, and negative-space choreography.
  - Bespoke micro-interactions, navigation mechanics, and card/row anatomy.
- If swapping out the demo images reduces the site to an interchangeable starter template, the structural design has FAILED.

## GATE 3 — DESIGNED, NOT ASSEMBLED (INTENTIONALITY & RESTRAINT)
> *"Can you explain why each major design choice was made, or were some just the default or the common approach? The themes that stand out feel designed, not assembled."*

- **Know what to leave out:** Deliberate omission is a mark of design judgment. Do not include a section, badge, control, or meta-field merely because other templates have it.
- **Inter-section rhythm:** Vary density, pacing, scale, and visual weight across the page (e.g., expansive cinematic pause followed by high-density structured specification, followed by asymmetric editorial narrative).
- **Zero mechanical stacking:** Avoid uniform vertical padding and repetitive container structures from top to bottom. Every transition between sections must feel curated.

## GATE 4 — DISTINCTIVE BEYOND FREE THEMES
> *"Does it offer layouts, visuals or interactions that merchants can't already get from existing themes, including our free themes?"*

- Ask of every major template (Home, Collection, PDP, Editorial, Navigation, Cart): *"Can a merchant achieve 90% of this exact layout and feel using a free default theme (such as Dawn) just by changing fonts and colors?"*
- If the answer is yes, the layout or interaction MUST be redesigned until it offers a genuinely distinct structural or interactive advantage.

## GATE 5 — ZERO HOMEPAGE BIAS (CONSISTENT ACROSS EVERY TEMPLATE)
> *"Is the design quality the same across every template, including product, collection, cart, search and mobile, and not just the homepage?"*

Reviewers evaluate the entire template system, not just the homepage. A drop in design quality after clicking past the homepage results in immediate rejection. Every secondary page and surface MUST receive the same level of art direction as the homepage:
- **Product Detail Pages (PDP):** Must go beyond a generic 50/50 image-left / buy-box-right default. Design how specifications, usage rituals, gallery crops, cross-sells, and purchase controls express the brand's point of view.
- **Collection / Catalog / Archive Pages:** Must offer distinctive filtering, sorting, editorial pacing, or view-mode mechanics — never just an unstyled 3x4 uniform grid under a plain title banner.
- **Cart, Search, & Filter Drawers/Pages:** Including empty states, zero-search-result states, loading states, and quick-add interactions must look bespoke to the design system, not like unstyled framework drawers.
- **Editorial, About, Contact, FAQ, 404, & Policy Pages:** Must carry the same signature grid, typography, and restraint as the flagship pages.
- **Mobile & Tablet Viewports:** Must preserve the template's distinctive personality and usability through intentional mobile choreography, not passive vertical column stacking.

## GATE 6 — MULTI-BRAND MERCHANT EXPRESSION & REAL-CONTENT RESILIENCE
> *"Could merchants use it to build clearly different brands, and does it hold up with real content and different settings?"*

- **Brand Expression:** The design architecture must remain cohesive and elevated when a customer changes the color palette, typography pairing, or brand tone (e.g., from warm botanical to stark clinical or dark artisanal — and for Shopify, across multiple distinct Theme Presets).
- **Real-Content Stress Testing:** Layouts must not break or lose their visual integrity when merchants use:
  - Product titles of varying lengths (1 word vs. 12 words / 60+ characters).
  - Mixed product image aspect ratios (1:1 square, 3:4, 4:5 portrait, 16:9 landscape) or transparent vs. solid backgrounds.
  - Products with 0 variants vs. 20 variants, missing badges, or long descriptions.
  - Catalogs/collections with 1 item, 5 items, or 100+ items.

---

# PART VII — ANTI-AI-SLOP WEB DESIGN RULES (35 PRINCIPLES)

## Core principle

Design for the specific product, audience, and task — never for a generic category of website.

The goal is not to avoid certain styles. Purple, beige, bento grids, dark mode, centered heroes, shadcn, gradients, serif typography, and cards are not inherently bad.

The problem is **decision-less design**: choices made because they are defaults rather than because they serve the product.

A strong interface should feel like someone made a sequence of deliberate decisions for this exact product.

---

## 1. Establish a design direction before coding

Before building UI, explicitly determine:

- the page's primary job;
- the intended audience;
- the most important user action;
- one memorable visual idea;
- typography;
- color palette;
- spacing rhythm;
- grid/composition logic;
- radius system;
- elevation system;
- interaction behavior;
- motion behavior.

Do not allow the framework or AI model to silently make these decisions.

Every visual decision must have a reason tied to:

- content,
- interaction,
- hierarchy,
- usability,
- brand,
- or domain.

---

## 2. Let the layout explain the interface

Avoid unnecessary secondary labels and descriptions.

Let hierarchy, grouping, alignment, spacing, typography, composition, and proximity do the explaining.

Do not automatically add:

- eyebrow labels;
- section labels;
- badges;
- pills;
- decorative numbering;
- dividers;
- borders;
- icons;
- helper descriptions;
- tiny uppercase metadata;
- redundant subtitles.

These elements should exist only when they encode useful information.

A strong rule:

> Never add UI to explain UI. First try to make hierarchy, layout, wording, and interaction self-explanatory. Add explanatory UI only when ambiguity remains.

However, do not confuse minimalism with removing necessary UX information.

Inputs, icon buttons, ambiguous navigation, unfamiliar controls, and accessibility-critical actions still need clear labels or accessible names.

---

## 3. Do not default to the standard AI landing-page skeleton

Never automatically generate:

Hero  
→ logo wall  
→ three feature cards  
→ statistics  
→ testimonials  
→ pricing  
→ FAQ  
→ final CTA  
→ footer

Sections should exist because they answer real questions in the user's decision journey.

Examples:

- What is this?
- Why should I care?
- How does it work?
- What does the actual product look like?
- Is this suitable for me?
- What evidence supports the claim?
- What happens next?

Let the information architecture determine the section order.

Do not let a landing-page template determine the content.

---

## 4. Avoid generic hero sections

Do not automatically use:

- badge;
- centered H1;
- subtitle;
- two CTAs;
- decorative gradient;
- floating screenshot.

Do not automatically use:

text left  
+ image right  
+ 50/50 split.

Choose the hero composition based on the strongest product asset or message.

Possible directions include:

- product-led;
- image-led;
- editorial;
- asymmetric;
- oversized typography;
- immersive visual;
- demo-led;
- object-led;
- data-led;
- narrative;
- intentionally sparse;
- intentionally dense.

If a split hero is appropriate, do not automatically use equal columns.

Consider:

- 4:6;
- 5:7;
- overlap;
- offset;
- crop;
- asymmetric negative space;
- non-centered vertical alignment.

---

## 5. Content determines layout

Never force content into three equal feature cards simply because three-column grids look clean.

Do not invent a third item merely to complete a row.

Do not reduce five meaningful concepts to three because the template expects three.

The number, size, and hierarchy of visual elements should reflect the actual importance of the content.

Content determines layout.

Layout does not determine content.

---

## 6. Avoid excessive cardification

Not everything needs to live inside a rounded rectangle.

Before using a card, first ask whether grouping can be communicated through:

- whitespace;
- alignment;
- typography;
- proximity;
- background;
- section structure;
- scale;
- rhythm.

Avoid:

- card inside card;
- card inside panel inside card;
- every feature having a box;
- every metric having a box;
- every sentence becoming a card.

A container should exist because it establishes a meaningful boundary.

Do not use containers merely because empty space feels uncomfortable.

---

## 7. Do not ship framework defaults

If using:

- shadcn;
- Tailwind;
- Material;
- Bootstrap;
- Radix;
- Chakra;
- another design system;

do not ship its default visual personality unchanged.

Customize:

- font family;
- font scale;
- neutral palette;
- primary colors;
- spacing;
- radii;
- border treatment;
- shadows;
- controls;
- states;
- proportions;
- density;
- motion.

A component library should provide infrastructure, not identity.

---

## 8. Define a deliberate shape language

Do not use:

`rounded-xl`  
`rounded-2xl`  
`rounded-3xl`

on everything.

Do not make every button a pill.

Create a small, deliberate radius scale.

For example:

- small controls;
- standard surfaces;
- major containers;
- fully circular/pill elements only where justified.

Shape should communicate hierarchy and personality.

Do not let one radius become the personality of the entire website.

---

## 9. Use depth intentionally

Do not automatically combine:

- border;
- shadow;
- blur;
- glow;
- gradient;
- glass.

Choose a coherent depth model.

Use shadows when they communicate elevation.

Use borders when they communicate boundaries.

Use blur/glass when transparency or layering has meaning.

Use glow when light or emphasis is genuinely part of the aesthetic.

Do not use these as generic "make it premium" effects.

---

## 10. Avoid default AI color palettes

Do not automatically use:

- purple → blue gradients;
- indigo SaaS palettes;
- gradient text;
- black + neon cyan;
- black + neon purple;
- generic cream + terracotta;
- fashionable color combinations disconnected from the product.

Choose colors from:

- brand identity;
- product domain;
- physical materials;
- imagery;
- audience;
- cultural context;
- desired emotional tone.

There is nothing wrong with purple, beige, orange, neon, or gradients.

The problem is using them because nobody made a color decision.

---

## 11. Choose typography deliberately

Do not automatically choose:

- Inter;
- Geist;
- Roboto;
- Space Grotesk;
- oversized editorial serif;
- trendy italic serif;

simply because they commonly appear in generated interfaces.

Choose typography according to:

- product character;
- reading density;
- content type;
- audience;
- visual identity.

Create hierarchy through:

- scale;
- weight;
- width;
- line height;
- spacing;
- alignment;
- contrast.

Do not fake hierarchy by randomly coloring or italicizing one word in every headline.

---

## 12. Avoid decorative typography clichés

Do not automatically use:

- ALL CAPS everywhere;
- excessive letter spacing;
- tiny uppercase eyebrows;
- monospace metadata;
- italic keywords;
- colored single words;
- decorative punctuation.

Monospace should generally represent:

- code;
- IDs;
- technical values;
- structured data;

unless the visual identity intentionally uses it.

Uppercase and tracking should have a reason beyond "editorial feel".

---

## 13. Icons must earn their place

Do not put an icon beside every heading.

Do not put icons in every feature card merely because the component has an icon slot.

Do not automatically use:

- Sparkles;
- Zap;
- Shield;
- BarChart;
- CheckCircle;
- ArrowRight.

Use icons when they improve:

- recognition;
- scanning;
- understanding;
- action clarity.

Prefer icons tied to actual objects or actions.

Do not use emoji as substitute interface icons unless emoji are explicitly part of the product's visual language.

---

## 14. Prefer domain-specific visuals

Prefer:

- real product screenshots;
- actual workflows;
- meaningful illustrations;
- real objects;
- realistic data;
- domain-specific diagrams;
- product demonstrations.

Avoid generic visual props such as:

- meaningless abstract blobs;
- fake terminals;
- random floating dashboards;
- meaningless charts;
- generic glowing orbs;
- decorative browser windows;
- fake analytics.

Visuals should reveal something about the product.

They should not merely prove that a hero section contains an image.

---

## 15. Never fabricate credibility

Never invent:

- testimonials;
- customer quotes;
- company logos;
- user counts;
- awards;
- ratings;
- revenue;
- performance metrics;
- review numbers;
- case-study results.

If real social proof does not exist, remove the section.

Do not fill credibility gaps with fictional content.

---

## 16. Write specific copy

Avoid generic AI/SaaS phrases such as:

- Transform your workflow
- Unlock your potential
- Supercharge your productivity
- Seamless experience
- Powerful solution
- Everything you need
- Built for modern teams
- Work smarter, not harder
- Take your business to the next level

Prefer specific language describing:

- the user;
- the object;
- the action;
- the constraint;
- the result.

The copy should become less reusable for unrelated companies.

That is a good sign.

---

## 17. Use specific CTA labels

Prefer:

- Create workspace
- Import CSV
- Upload file
- Compare plans
- Generate report
- Book demo
- Start analysis
- Add teammate

over generic labels such as:

- Get started
- Learn more
- Explore
- Discover
- Try now

when the actual action can be named.

Buttons should tell users what will happen.

---

## 18. One text element = one job

Do not repeat the same information across:

eyebrow  
→ heading  
→ subtitle  
→ paragraph  
→ card title  
→ helper text.

Ask whether each piece of copy adds new information.

If the heading already explains the section, do not add a label saying what type of section it is.

If the description simply restates the heading, remove it.

---

## 19. Maintain consistent language

Use the same terminology throughout the product.

Do not rename the same concept across different screens.

For example, avoid calling the same object:

- workspace;
- project;
- board;
- collection;

unless they genuinely mean different things.

Actions should retain consistent names before, during, and after interaction.

---

## 20. Motion must communicate something

Motion should communicate:

- state change;
- causality;
- hierarchy;
- direction;
- continuity;
- focus.

Do not automatically:

- fade every section upward;
- scale every card on hover;
- animate every icon;
- add parallax;
- add scroll-jacking;
- add bouncing CTAs;
- animate purely because animation is available.

One thoughtful transition is better than twenty generic animations.

---

## 21. Spend visual boldness selectively

Do not make every section compete for attention.

Choose one or two memorable visual moments.

Let surrounding UI remain disciplined.

Distinctive design requires contrast between:

- ordinary;
- important;
- exceptional.

If everything is loud, nothing is memorable.

---

## 22. Design real product states

Do not design only the perfect screenshot.

Explicitly design:

- loading;
- skeleton;
- empty state;
- no results;
- error;
- success;
- disabled;
- hover;
- focus;
- selected;
- validation error;
- permission denied;
- offline;
- destructive confirmation;
- long content;
- missing images;
- large datasets;
- slow requests.

A real product is mostly states, not hero screenshots.

---

## 23. Design mobile intentionally

Mobile should not simply be desktop columns stacked vertically.

Reconsider:

- content priority;
- navigation;
- content order;
- image crop;
- table behavior;
- spacing;
- information density;
- touch targets;
- sticky controls;
- modal behavior;
- interaction patterns.

Test intermediate widths as well as standard breakpoints.

Do not assume desktop composition survives at 375 px.

---

## 24. Test uncomfortable content

Test the interface using:

- very long titles;
- short titles;
- missing avatars;
- missing images;
- translated text;
- long German-like strings;
- zero results;
- hundreds of results;
- malformed input;
- slow requests;
- empty accounts;
- restricted permissions;
- unexpected values.

Layouts that only work with ideal placeholder content are unfinished.

---

## 25. Maintain a coherent system across routes

Do not make each screen feel independently generated.

Repeated components should share:

- typography;
- dimensions;
- spacing;
- radius;
- color semantics;
- borders;
- elevation;
- state styling;
- interaction patterns;
- naming.

However, consistency does not mean copying the same page composition everywhere.

A dashboard, settings page, onboarding flow, and marketing page may use different layouts while clearly belonging to the same system.

---

## 26. Accessibility is part of design

Always consider:

- semantic HTML;
- keyboard navigation;
- visible focus;
- accessible names;
- input labels;
- logical heading order;
- sufficient contrast;
- touch targets;
- error messaging;
- reduced-motion preferences;
- screen-reader behavior.

Do not remove useful labels merely to achieve minimalism.

"Let the layout do the explaining" applies to unnecessary explanatory chrome, not essential accessibility information.

---

## 27. Use the substitution test

Before finishing, ask:

> If I replace the logo, company name, and primary color with another startup, would 90% of this website still make sense?

If yes, the design is probably too generic.

Make the interface more specific to:

- the product;
- the industry;
- the workflow;
- the audience;
- the content;
- the brand.

---

## 28. Use the decoration-off test

Imagine removing:

- gradients;
- glow;
- shadows;
- icons;
- badges;
- decorative illustrations;
- animation.

Does the information hierarchy still work?

If not, the visual structure is weak.

Fix:

- typography;
- layout;
- grouping;
- content order;
- spacing;

before restoring decoration.

---

## 29. Use the second-screen test

Open another route in the product.

Ask:

> Does this clearly belong to the same design system?

Then ask:

> Does it also avoid mechanically repeating the exact same composition?

The design system should stay consistent.

The compositions do not need to be identical.

---

## 30. Use the uncomfortable-state test

Ask whether the interface remains understandable when:

- data is missing;
- data is loading;
- the request fails;
- text becomes very long;
- there are hundreds of records;
- content is translated;
- the screen becomes narrow;
- permissions disappear.

If the interface breaks conceptually, not merely visually, it is unfinished.

---

## 31. Remove something before shipping

Before finalizing a page, identify at least one decorative element and ask:

> What information or function does this provide?

If the answer is only:

"it makes the page look designed"

remove it.

---

## 32. Common AI-generated design tells

Treat these as warning signs, not absolute bans:

- centered hero with badge + H1 + subtitle + two CTAs;
- default left-text/right-image hero;
- three identical feature cards;
- excessive blank space without compositional purpose;
- every section using `py-24` or `py-32`;
- every element inside a card;
- nested cards;
- large rounded rectangles everywhere;
- default shadcn appearance;
- purple/blue gradients;
- cream + terracotta trend palette;
- neon dark mode;
- gradient headline text;
- Instrument Serif / oversized serif inserted to look tasteful;
- Inter/Geist everywhere;
- tiny tracked uppercase labels;
- random monospace metadata;
- one italic/color word per heading;
- Sparkles/Zap/Shield icons everywhere;
- emoji feature icons;
- fake terminal windows;
- fake dashboards;
- meaningless upward charts;
- fake testimonials;
- generic statistics;
- repeated fade-up animations;
- scale-on-hover on every card;
- parallax without purpose;
- every CTA saying "Get Started";
- copy such as "Unlock", "Transform", "Supercharge", "Seamless";
- mobile being merely stacked desktop;
- only happy-path screens being designed.

Seeing one of these does not mean a website is AI-generated.

Seeing many defaults combined without a coherent reason is the stronger signal.

---

## 33. The core diagnosis of AI-looking design

The strongest sign is not a particular aesthetic.

It is:

**generic + unresolved + unreviewed**

Generic:
The design could belong to almost any product.

Unresolved:
Important visual/system decisions were left to defaults.

Unreviewed:
Nobody came back afterward and questioned those defaults.

---

## 34. Think in terms of decisions, not forbidden styles

Do not think:

"Never use purple."

Think:

"Why is purple appropriate for this product?"

Do not think:

"Never use cards."

Think:

"What boundary or grouping does this card communicate?"

Do not think:

"Never center a hero."

Think:

"Does centered composition best serve this message?"

Do not think:

"Never use serif."

Think:

"What personality or reading experience does this typeface create?"

---

## 35. Important mental model

Remember:

AI chooses purple because you did not choose a color.

AI chooses three cards because you did not choose the information architecture.

AI writes a subtitle because you did not decide whether a subtitle is necessary.

AI uses rounded-2xl because you did not define a shape language.

AI adds a fade-up animation because you did not define motion behavior.

AI creates generic copy because you did not provide a specific product vocabulary.

Defaults fill every design decision that the designer leaves unresolved.

Therefore:

**Resolve important design decisions intentionally before asking AI to generate the interface.**

---

# FINAL STANDARD

The final interface should feel like:

> Someone made a sequence of deliberate decisions for this exact product, its content, its users, and its context.

It should not feel like:

> A model successfully completed a generic "build me a modern website" prompt.

