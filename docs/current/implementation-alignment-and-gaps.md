# Implementation Alignment And Gaps

## Current Alignment

The current codebase broadly aligns with the consolidated docs:

- Product identity is StoX in visible app header/title areas.
- Laravel routes and React navigation match the feature taxonomy in [Frontend And Navigation](./frontend-and-navigation.md).
- Portfolio/cash/accounting, market data, screeners, strategy, recommendations, execution, analytics, notifications, knowledge, and admin features all have concrete controllers, services, models, migrations, and tests.
- `/api/v1` is additive and Sanctum-authenticated.
- Cached market data and data-quality gates are central to downstream trading workflows.
- Artifact/registry infrastructure exists alongside legacy screeners and strategies.
- Knowledge Board and Wiki are implemented as related but distinct content types.

## Known Gaps And Residual Risks

1. Naming legacy remains intentional but noisy. Repository path, database table prefix, CSS prefix, and some code identifiers still use Lido/lido/portfolio naming while product UI uses StoX. This is not automatically a bug, but new user-facing surfaces should say StoX.
2. Static in-app help under `app/public/docs` may drift from `docs/current` because it is served product content, not the source-of-truth corpus. When changing product behaviour, update both if the help page describes that behaviour.
3. Classic `/api/*` analytics and `/api/v1/analytics/*` overlap. Keep both documented until a deliberate consolidation happens.
4. Discovery/candidates routes and pages exist but are not sidebar-first in the current UX. Treat them as inspection/debug surfaces unless product direction changes.
5. Historical docs mentioned JWT and versioned precedence chains. Current implementation uses Sanctum; archived JWT language must not drive new changes.
6. V7 fundamentals and ML are implemented as analytical/admin capabilities, but deterministic strategy remains authoritative. Do not let ML silently override strategy/recommendation decisions without an explicit future spec update.
7. Some older docs listed optional test gaps around cash adjust, calendar reminder dedupe, dashboard composition, explorer manual relative strength, and snapshot soft-fail assertions. The test suite has broad coverage now, but these areas remain good targets when bugs appear.
8. `app/public/docs` is large and manually generated/static. Broken help links can look like source spec bugs; check the app help asset before changing `docs/current`.

## Test Anchors

Use these tests as starting points when debugging matching domains:

- Auth/admin/security: `AuthSessionTest`, `AuthCsrfLoginTest`, `UserManagementTest`, `UserInviteTest`, `PasswordResetLinkTest`, `TotpFlowTest`, `V6PersonalApiTokenTest`
- Portfolio/accounting: `TransactionUpdateTest`, `TransactionSellRealizationTest`, `HistoricalHoldingsTest`, `PortfolioSnapshotApiTest`, `V5CashStatementTest`, `V5AccountPerformanceApiTest`, `V5AccountTaxReportApiTest`
- Market data/data quality: `DailyMarketSyncTest`, `UniversePriceSyncApiTest`, `DataQualityApiTest`, `DataQualityPipelineGatingTest`, `DatasetVersioningTest`, `FundamentalDataIntegrationTest`, `MlScoringLifecycleTest`
- Screeners/artifacts: `ScreenerTest`, `ScreenerRegistryApiTest`, `F060SharedScreenerAuthzTest`, `ArtifactLibraryApiTest`, `ArtifactActionApiTest`, `ArtifactBindingServiceTest`
- Strategy/recommendations: `V3RecommendationGenerationTest`, `TradingOsPipelineTest`, `MarketGateRecommendationTest`, `F137RecommendationPreviewTest`, `RecommendationLendingLifecycleTest`
- Execution/broker/safety: `LiveExecutionFeatureTest`, `AdvancedOrdersFeatureTest`, `ExecutionSafety`-related tests, `KiteCallbackTest`, `KiteReadinessReminderTest`
- Analytics/backtesting/review: `ExplorerAnalyticsTest`, `DashboardGrowthTest`, `DashboardTopMoversTest`, `V5BacktestLifecycleTest`, `V5PortfolioReplayFoundationTest`
- Notifications/calendar/alerts: `NotificationPublisherTest`, `NotificationDeliveryProcessorTest`, `NotificationSettingsApiTest`, `CalendarEventTest`, `AlertPolicyTest`, `AdminOperationalAlertTest`
- Knowledge/wiki: `KnowledgeBoardTest`, `KnowledgeBoardImageTest`, `V5WikiFoundationTest`, `V6ContextualNotesTest`

## Archive Use

Archived docs are available under [../archive/](../archive/) for rare historical questions. When an archived doc conflicts with this corpus, prefer current docs and implementation. If the implementation appears wrong, open a bug or patch and update this file.

