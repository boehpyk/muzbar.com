# Resonance Marketplace & Ecosystem Platform

**Version:** v1
**Status:** Draft

## 1. Product Overview

Resonance Marketplace & Ecosystem Platform is a modern refactoring of a hyper-granular musical instrument marketplace and local music services directory. The platform leverages advanced multi-parameter faceted search to connect buyers with highly specific gear configurations (e.g., brand, model, string count, orientation), while integrating a localized map-based directory of rehearsal spaces, recording studios, and music shops to drive regional community engagement.

## 2. Goals

### Business Goals
*   Achieve financial self-sufficiency by generating enough monthly recurring revenue (MRR) from premium gear listings and featured service provider subscriptions to fully offset all infrastructure and hosting costs.

### User Goals
*   Frictionless listing creation and onboarding, minimizing the time and effort required for an instrument seller to go from landing on the homepage to publishing a highly detailed, schema-accurate listing.
*   Effortless discovery of ultra-specific instrument configurations without sifting through irrelevant keyword results.

### Non-Goals
*   In-platform payment processing or escrow for peer-to-peer gear sales at launch; buyers and sellers will arrange transaction terms directly via external contact methods.
*   Native Mobile Apps; the initial launch will focus exclusively on a responsive web application.

## 3. User Personas

### Key User Types
*   **Individual Gear Seller / Buyer:** A musician or hobbyist looking to clear out gear or locate a highly specific model variation.
*   **Commercial Music Shop Owner:** Independent local music shop owners looking to extend their digital footprint and sync local inventory.
*   **Local Service Provider:** Business owners managing physical locations (studios, rehearsal spaces) who want to attract local musicians.

### Basic Persona Details
*   **Individual Gear Seller / Buyer:** Low to Moderate frequency. Logs in to post an ad, checks listing performance, or browses when searching for specific gear.
*   **Commercial Music Shop Owner:** Moderate to High frequency. Interacts weekly to manage store listings, evaluate listing analytics, or automate inventory syncing via API.
*   **Local Service Provider:** Low to Moderate frequency. High initial activity during profile setup, followed by occasional updates to rates, equipment specs, or premium promotion blocks.

### Role-Based Access
*   **Guest:** Unauthenticated browsing, execution of faceted searches, and viewing localized directory maps.
*   **Individual User:** Authenticated role allowed to create, edit, renew, and delete personal gear advertisements and manage alert notifications.
*   **Business User:** Authenticated corporate role allowed to manage commercial shop inventory, configure service provider directory profiles, and purchase featured visibility tiers.
*   **Admin:** Complete global system moderation, dynamic category/schema management, infrastructure health tracking, and user ban capabilities.

## 4. User Stories

*   **US-1:** As an **Individual Gear Seller**, I want to quickly authenticate and select pre-defined dropdown specifications (e.g., brand, model, string count, hand orientation) so that my instrument is accurately indexed without typing massive walls of text.
*   **US-2:** As a **Gear Buyer**, I want to apply granular filters so that I only see exact matches instead of weeding through irrelevant listings.
*   **US-3:** As a **Service Provider (Studio Owner)**, I want to create a business profile with coordinates, equipment lists, and room specs so that local musicians can find my physical location via map searches.
*   **US-4:** As a **Business User**, I want to purchase a featured listing boost or monthly subscription premium badge so that my shop or studio appears at the top of local search results to drive more leads.
*   **US-5:** As a **Gear Buyer**, I want to subscribe to a specific, highly granular search query using my email address so that I am automatically notified the moment a matching instrument is published to an otherwise empty database.

## 5. Functional Requirements

*   **FR-1:** The system must allow admins to dynamically create, edit, and delete instrument categories and their associated attribute schemas via a dedicated administrative dashboard (implements US-1).
*   **FR-2:** The registration flow must support OAuth 2.0 (Google) for swift user onboarding and simple API-key generation for external commercial store inventory syncs (implements US-1, US-4).
*   **FR-3:** The search engine must update its facets dynamically based on the category selected, resolving filtering requests down to specific structural attributes within a 200 ms response window (implements US-2).
*   **FR-4:** The service directory must fetch and display map markers using geospatial coordinates via a map integration API (implements US-3).
*   **FR-5:** The billing subsystem must toggle a "Featured" flag on listings and service profiles upon successful webhook verification from a payment provider (implements US-4).
*   **FR-6:** The notification engine must capture search filter configurations alongside a user's verified email, evaluating new listing inputs against active alert parameters and firing an immediate matching email notification upon a successful lookup hit (implements US-5).

## 6. User Experience

### Entry Points & First-Time User Flow
*   Unauthenticated guests land directly on a clean homepage dominated by a multi-parameter search layout. 
*   Guests can completely configure searches and view map records without signing up. If they attempt to execute an action-oriented task ("Sell Gear", "Add Studio", or "Subscribe to Search"), the application prompts a 1-click social authentication overlay. Upon login success, the user is redirected cleanly to their intended action.

### Core Experience
*   Gear publication operates as a single-page responsive form. Selecting a base category (e.g., "Bass Guitar") dynamically morphs the lower half of the form to present standardized parametric dropdowns ("Strings", "Orientation", "Body Type") instead of unstructured text areas. 
*   Active advertisements automatically expire and hide from public view after 30 days. The system triggers an automated email warning 3 days prior, providing a single-click renewal hyperlink to extend visibility by another 30-day block.

### Advanced Features & Edge Cases
*   When a search query yields 0 results, the system presents a prominent "Subscribe to this Search" module, caching the exact active facet parameters against the user's email address.
*   Public listings hide raw user email addresses to prevent scraping, exposing only the seller's explicitly preferred external channels (e.g., direct mail-relay forms, phone, or Telegram handles).

### UI/UX Highlights
*   Dynamic, non-destructive form transformations that morph gracefully without losing previously inputted basic data (like price or title).
*   High-contrast, mobile-responsive map cards that allow users to swipe through local studios smoothly while the map centers dynamically on their selected pin.

## 7. Narrative

Max is a bass player in Gothenburg trying to track down a highly specific instrument—a left-handed Fender Precision V Bass with 5 strings. He loads up the platform on his phone. Instead of fighting an empty keyword box that returns thousands of unrelated string choices, he navigates the clean drop-down selectors. Within three taps, the platform sifts its index and presents him with two active listings matching that precise technical configuration. 

He hits the Telegram link on the primary listing to arrange a local meetup with the seller. Needing a place to test out the gear safely, Max jumps over to the platform's local map view. It highlights three rehearsal studios within a 5-kilometer radius, complete with their gear lists and room setups. Within ten minutes, Max has found the gear, booked a room externally, and connected directly with the community.

## 8. Success Metrics

| Metric | Type | Baseline | Target | Timeframe |
| :--- | :--- | :--- | :--- | :--- |
| **Monthly Recurring Revenue (MRR) vs. Infrastructure Costs** | Business | \$0 / month | 100% hosting cost coverage + 20% buffer margin | Within 6 months of public launch |
| **Form Completion Rate** | User | _TBD_ | Greater than 75% completion rate | Within 30 days of launch |
| **Time-to-Publish** | User | _TBD_ | Average under 3 minutes for returning users | Within 30 days of launch |
| **Faceted Search Response Latency** | Technical | _TBD_ | Less than 200 ms under a 50 concurrent request parallel load | Within 14 days of deployment |

## 9. Technical Considerations

### Integration Points
*   **Geospatial Mapping:** Google Maps API (or OpenStreetMap fallback via Leaflet) for physical directory coordinate lookup and visualization.
*   **Payment Gateway:** Stripe API webhooks to track premium visibility invoicing and commercial account management.
*   **Transactional Email:** Third-party SMTP Relay (e.g., SendGrid or Postmark) for highly reliable 30-day ad renewal warnings and search subscription matching notifications.

### Data Storage & Privacy
*   **Relational Engine:** PostgreSQL managing core business units, transactional logs, user records, and basic directory tables.
*   **Search Engine:** Meilisearch or Elasticsearch instance executing high-speed multi-parameter faceted indexation.
*   **Privacy Guardrails:** Strict isolation of user email addresses; public interfaces handle communication via proxy mail relays or explicit third-party social links.

### Scalability & Performance
*   Single-instance optimization targeting deployment onto a single VDS via Docker Compose.
*   Database structures must establish robust composite indexes covering high-frequency search vectors (Brand, Model, Geo-coordinates).

### Potential Challenges
*   **Schema Drift:** Handling structural changes to metadata properties when administrators inject or delete custom attributes from live category trees without invalidating downstream search indices.
*   **IP Reputation Constraints:** Running email alert engines off a single VDS requires defensive routing through external SMTP systems to circumvent aggressive spam categorization blocks.

## 10. Build Phases

### Phase 1: Foundation & Data Model Schema
*   Provision VDS environment and configure base multi-container `docker-compose.yml` environment.
*   Establish PostgreSQL relational architecture alongside the dynamic administrative Category/Attribute schema layout engine.
*   Implement 1-click OAuth authentication flows.

### Phase 2: Faceted Search & Listing Marketplace
*   Integrate Meilisearch container layer and synchronize relational product tables with search indices.
*   Build out the morphing, single-page listing creation wizard.
*   Deploy the automated 30-day advertisement lifecycle system, cron engines, and SMTP transactional email warning routes.
*   Implement the "Subscribe to Search" alert caching mechanism.

### Phase 3: Directory Mapping, Monetization & API Sync
*   Inject geospatial coordinate visual layers mapping local music shops, studios, and spaces.
*   Integrate Stripe payment webhook processing hooks to support premium visibility toggles.
*   Expose programmatic API key access endpoints allowing commercial shops to sync inventory automatically.

---

# Review Notes

## Weak spots
*   **Telemetry Baselines (_TBD_):** Because the platform treats a 5-year abandoned dataset as an effective greenfield deployment, user experience baselines (`Form Completion Rate`, `Time-to-Publish`) cannot be cleanly estimated. Initial baseline metrics must be gathered during an early alpha preview sequence to set exact target baselines.
*   **Third-Party SMTP Dependency:** The financial self-sufficiency model depends heavily on transactional email hooks for conversions (ad renewals and search alerts). Free tier alterations by provider relays (e.g., SendGrid/Postmark) will directly impact operational margins if VDS volume spikes.

## Suggested validations
1.  **Search Latency Benchmark:** Confirm that the chosen search engine container (e.g., Meilisearch) running side-by-side with relational layers on a single VDS successfully maintains sub-200 ms response times while scaling past 10,000 mock items under a 50 concurrent request parallel load.
2.  **Schema Mutation Proof of Concept:** Run an early staging verification cycle ensuring that adding new relational data columns (e.g., "Fretboard Wood") via the Admin interface successfully propagates schema changes to the UI form dynamic rendering logic without corrupting existing database search records.
3.  **Spam Filter Clearance:** Verify that systemic notifications containing single-click renewal links clear major inbox provider filtering algorithms reliably when dispatched through the chosen transaction SMTP pipeline.