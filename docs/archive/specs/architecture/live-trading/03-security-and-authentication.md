1. Purpose

2. Security Principles

3. Authentication

4. Authorization

5. Trading Permission Model

6. Trading Session

7. Multi-Factor Authentication

8. Trading PIN

9. Broker Credential Management

10. Sensitive Operations

11. Automation Authorization

12. Kill Switch

13. Audit Requirements

14. Future Compatibility

15. Out of Scope

# Section 1 – Purpose & Security Principles

## Purpose

This specification defines the security architecture for the StoX Live Trading subsystem.

The objective is to ensure that no unauthorized, unintended, or unsafe action can result in execution of live broker orders.

The Security subsystem protects:

- User identities
- Trading permissions
- Broker credentials
- Trading sessions
- Automation capabilities
- Portfolio assets
- Administrative functions

This specification governs all security-sensitive operations performed within the Live Trading subsystem.

---

# Scope

This specification covers:

- Authentication
- Authorization
- Trading permissions
- Multi-factor authentication
- Trading sessions
- Trading secrets
- Broker credential protection
- Automation authorization
- Kill switches
- Audit requirements
- Security event logging

This specification does not define:

- Broker authentication protocols
- Web application authentication implementation
- Password storage implementation
- Cryptographic algorithms
- Regulatory compliance requirements

Those topics are defined elsewhere or delegated to implementation.

---

# Security Objectives

The Security subsystem SHALL ensure:

- Only authenticated users access StoX.
- Only authorized users perform trading operations.
- Every sensitive operation requires appropriate verification.
- Automated trading cannot bypass security controls.
- Broker credentials remain protected.
- Every security-sensitive action is auditable.
- Security policies remain independent of trading strategies.

---

# Security Architecture

The Security architecture consists of three independent layers.

Identity

↓

Authorization

↓

Trading Unlock

↓

Execution

Each layer has a distinct responsibility.

---

# Layer 1 – Identity

Identity answers the question:

> "Who is the user?"

Identity is established through standard application authentication.

Examples include:

- Username & Password
- Single Sign-On (future)
- OAuth (future)
- Enterprise Identity Providers (future)

Identity establishes the authenticated user but does not grant trading privileges.

---

# Layer 2 – Authorization

Authorization answers the question:

> "What is this user allowed to do?"

Authorization determines:

- Portfolio access
- Administrative privileges
- Trading permissions
- Configuration permissions
- Broker management permissions

Authorization is evaluated for every protected operation.

---

# Layer 3 – Trading Unlock

Trading Unlock answers the question:

> "Has this user recently completed the additional verification required to perform trading operations?"

Trading Unlock is independent of application login.

A user may remain logged in while trading remains locked.

Trading Unlock requires successful completion of additional security verification before sensitive trading operations are permitted.

---

# Separation of Responsibilities

Authentication SHALL identify the user.

Authorization SHALL determine permissions.

Trading Unlock SHALL authorize execution of sensitive trading operations.

These responsibilities SHALL remain independent.

---

# Principle 1 – Least Privilege

Users SHALL receive only the permissions required to perform their intended activities.

Privileges shall not be granted implicitly.

---

# Principle 2 – Explicit Trust

Every sensitive operation SHALL require explicit authorization.

Trust established for one operation SHALL NOT automatically apply to unrelated operations.

---

# Principle 3 – Defense in Depth

Security controls shall exist at multiple independent layers.

Failure of one layer shall not automatically compromise the remaining layers.

---

# Principle 4 – Fail Secure

When the system cannot determine whether an operation is safe, the operation SHALL be denied.

Examples include:

- Unknown user
- Expired session
- Invalid broker state
- Missing permissions
- Incomplete verification

---

# Principle 5 – Auditability

Every security-sensitive action SHALL produce an immutable audit record.

Audit history SHALL support complete reconstruction of security events.

---

# Principle 6 – Separation of Duties

Where practical, administrative responsibilities and trading responsibilities shall remain independent.

Administrative privilege does not automatically imply permission to execute trades.

Trading permission does not automatically imply administrative access.

---

# Principle 7 – Automation Safety

Automation SHALL operate under explicit authorization.

Automation SHALL never inherit permissions simply because a user is logged in.

Automation authorization is independent of interactive user sessions.

---

# Principle 8 – Secure by Default

New users, portfolios, and broker connections SHALL begin in the most restrictive security state.

Sensitive capabilities require explicit enablement.

---

# Security Domains

The Security subsystem protects the following domains:

- User Identity
- Portfolio Access
- Trading Operations
- Broker Connectivity
- Automation
- Administrative Operations
- Security Configuration
- Audit Records

Each domain may enforce different security requirements.

---

# Security Boundaries

The following boundaries SHALL remain independent.

Application Authentication

↓

Trading Authorization

↓

Broker Authentication

↓

Broker Execution

Compromise of one boundary shall not automatically compromise another.

---

# Design Goals

The architecture has been designed to support future enhancements including:

- Hardware security keys
- WebAuthn
- Biometric authentication
- Enterprise SSO
- Multiple brokers
- Multiple administrators
- Regulatory compliance
- Fine-grained authorization policies

Future capabilities shall extend this architecture without requiring redesign.

# Section 2 – Permission Model & Operational Authorization

## Purpose

This section defines how StoX determines whether a user is permitted to perform a requested operation.

Authorization consists of two independent evaluations:

1. Permission Model (Static Authorization)
2. Operational Authorization (Runtime Authorization)

An operation is permitted only if both evaluations succeed.

---

# Authorization Flow

Identity

↓

Permission Evaluation

↓

Operational Authorization

↓

Execution

Failure at any stage immediately terminates the request.

---

# Permission Model

## Purpose

The Permission Model defines the capabilities assigned to a user.

Permissions represent long-lived privileges.

Permissions do not change based on runtime conditions.

---

# Design Principles

Permissions SHALL:

- be explicit
- be least-privilege
- be independently auditable
- never be inferred
- remain independent of execution state

---

# Permission Categories

## Portfolio Permissions

Examples:

- View Portfolio
- Edit Portfolio
- Delete Portfolio
- Manage Cash
- View Holdings

---

## Strategy Permissions

Examples:

- View Strategies
- Edit Strategies
- Import Strategy
- Export Strategy
- Publish Strategy

---

## Screener Permissions

Examples:

- Create Screener
- Edit Screener
- Delete Screener
- Share Screener
- Import Screener

---

## Live Trading Permissions

Examples:

- Connect Broker
- Disconnect Broker
- Execute Orders
- Approve Basket
- Cancel Basket
- Enable Live Trading

---

## Automation Permissions

Examples:

- Configure Automation
- Enable Semi-Automatic Mode
- Enable Fully Automatic Mode
- Pause Automation
- Resume Automation

---

## Administrative Permissions

Examples:

- Manage Users
- Manage Brokers
- View Audit Logs
- Configure Global Settings
- Configure Security Policies

---

# Permission Assignment

Permissions may be assigned:

- directly to a user
- through a role
- through future policy engines

Permission inheritance SHALL remain deterministic.

---

# Permission Evaluation

Every protected operation SHALL declare its required permissions.

Examples:

Execute Basket

↓

Requires

EXECUTE_TRADES

Enable Automation

↓

Requires

CONFIGURE_AUTOMATION

Manage Users

↓

Requires

MANAGE_USERS

---

# Operational Authorization

## Purpose

Operational Authorization evaluates runtime conditions.

Unlike permissions, these conditions may change continuously.

---

# Operational Checks

Typical checks include:

- Trading Session unlocked
- Kill Switch disabled
- Broker connected
- Broker authenticated
- Broker healthy
- Portfolio enabled for live trading
- User MFA verified
- Trading Secret verified
- Market open (where applicable)

Every required condition must succeed.

---

# Portfolio-Level Authorization

Each Portfolio may independently enable or disable Live Trading.

Possible states include:

- Live Trading Disabled
- Manual Trading Only
- Semi-Automatic Enabled
- Fully Automatic Enabled

Operations exceeding the configured mode SHALL be denied.

---

# Broker Authorization

Execution additionally requires:

- Broker configured
- Broker authenticated
- Broker session valid
- Broker capability available

Authorization SHALL fail if broker requirements are not satisfied.

---

# Automation Authorization

Automation is evaluated independently of interactive users.

Automation SHALL require:

- Automation enabled
- Automation permission
- Automation authorization token
- Active broker session
- Kill Switch inactive

Automation SHALL NOT depend on an active browser session.

---

# Security Evaluation Matrix

Example authorization requirements:

| Operation                 | Login | Permission           | Trading Unlock | Operational Authorization |
| ------------------------- | ----- | -------------------- | -------------- | ------------------------- |
| View Holdings             | ✓     | VIEW_HOLDINGS        |                |                           |
| Edit Strategy             | ✓     | EDIT_STRATEGY        |                |                           |
| Connect Broker            | ✓     | MANAGE_BROKER        | ✓              | ✓                         |
| Execute Basket            | ✓     | EXECUTE_TRADES       | ✓              | ✓                         |
| Enable Semi-Automatic     | ✓     | CONFIGURE_AUTOMATION | ✓              | ✓                         |
| Enable Fully Automatic    | ✓     | CONFIGURE_AUTOMATION | ✓              | ✓                         |
| Disable Kill Switch       | ✓     | MANAGE_SECURITY      | ✓              | ✓                         |
| Rotate Broker Credentials | ✓     | MANAGE_BROKER        | ✓              | ✓                         |

---

# Authorization Failures

Authorization failures SHALL include:

- failure category
- failed requirement
- timestamp
- user
- operation
- portfolio (if applicable)

Sensitive implementation details SHALL NOT be exposed to users.

---

# Audit Requirements

Every authorization decision SHALL be auditable.

Audit records SHALL include:

- operation
- requesting user
- permission evaluated
- operational checks evaluated
- final outcome
- correlation identifier

---

# Design Constraints

Permission Model SHALL remain independent of:

- Broker implementation
- Trading session
- Market status
- Execution mode

Operational Authorization SHALL remain independent of:

- User identity
- Role assignment
- Static permissions

Both evaluations SHALL succeed before execution proceeds.

---

# Future Compatibility

The authorization model supports future enhancements including:

- Attribute-Based Access Control (ABAC)
- Policy-Based Access Control (PBAC)
- Organization-level permissions
- Team-based portfolios
- Delegated portfolio management
- Institutional approval workflows
- Regulatory approval chains

These capabilities shall extend the existing model without changing its core principles.

# Section 3 – Trading Unlock & Trading Sessions

## Purpose

This section defines the Trading Unlock mechanism used by StoX to protect security-sensitive trading operations.

Trading Unlock provides an additional security layer beyond standard application authentication.

A user may remain logged into StoX while trading remains locked.

Trading operations become available only after a successful Trading Unlock.

---

# Objectives

Trading Unlock exists to:

- protect broker access
- prevent unauthorized trading
- reduce accidental executions
- secure automation configuration
- minimize repeated authentication prompts
- improve usability while maintaining security

---

# Design Principles

Trading Unlock SHALL:

- be independent of application login
- expire automatically
- require strong authentication
- remain portfolio-independent
- never bypass permission evaluation
- never bypass operational authorization

---

# Trading Unlock Workflow

Application Login

↓

Permission Evaluation

↓

Trading Locked

↓

User Requests Trading Unlock

↓

Multi-Factor Authentication

↓

Trading Secret Verification

↓

Trading Session Created

↓

Trading Enabled

↓

Session Expires

↓

Trading Locked

---

# Trading Session

## Purpose

A Trading Session represents a temporary authorization to perform security-sensitive trading operations.

Trading Sessions exist independently of browser login sessions.

---

# Session Creation

A Trading Session SHALL be created only after successful completion of:

- Identity Verification
- Permission Evaluation
- Multi-Factor Authentication
- Trading Secret Verification

All four requirements must succeed.

---

# Session Properties

Every Trading Session SHALL record:

- Session Identifier
- User
- Creation Time
- Expiry Time
- Last Activity
- Authentication Method
- MFA Method
- Trading Secret Version
- Client Identifier
- Device Identifier (future)
- IP Address
- Session Status

---

# Session States

Created

↓

Active

↓

Idle

↓

Expired

or

Revoked

or

Locked

---

## Active

Trading operations are permitted.

---

## Idle

No recent trading activity has occurred.

Idle sessions remain valid until timeout.

---

## Expired

Session lifetime exceeded.

Trading operations require a new Trading Unlock.

---

## Revoked

Session terminated by:

- User
- Administrator
- Security Policy
- Password Change
- Trading Secret Change

---

## Locked

Temporary security lock.

Examples include:

- Excessive failed PIN attempts
- Suspicious activity
- Security policy trigger

Locked sessions require re-authentication.

---

# Session Timeout

Trading Sessions SHALL automatically expire after a configurable period.

Default recommendation:

8 hours

Implementations may allow administrator configuration.

Expiry SHALL require a new Trading Unlock.

---

# Activity Renewal

Trading activity may optionally refresh the idle timeout.

The maximum session lifetime SHALL NOT be extended indefinitely.

Absolute session expiry SHALL always be enforced.

---

# Multi-Factor Authentication

Trading Unlock SHALL require Multi-Factor Authentication.

The preferred mechanism is:

Time-Based One-Time Password (TOTP)

Examples include:

- Google Authenticator
- Microsoft Authenticator
- Authy
- 1Password
- Other RFC 6238 compliant applications

SMS-based authentication is intentionally excluded.

Telegram SHALL NOT be used for authentication.

Telegram remains a notification channel only.

---

# Trading Secret

Trading Unlock additionally requires a Trading Secret.

The initial implementation shall use:

Numeric Trading PIN

The architecture supports future replacements including:

- Passphrase
- Hardware Security Keys
- WebAuthn
- Biometrics

Trading Secret verification occurs after successful MFA verification.

---

# Failed Verification

Repeated verification failures SHALL trigger security actions.

Examples include:

- Temporary lock
- Increased delay
- Session revocation
- Security notification
- Audit entry

Thresholds shall be configurable.

---

# Sensitive Operations

The following operations require an Active Trading Session:

- Connect Broker
- Disconnect Broker
- Execute Basket
- Approve Basket
- Enable Automation
- Disable Automation
- Enable Fully Automatic Mode
- Disable Kill Switch
- Change Broker Credentials
- Change Trading Secret

Future security-sensitive operations shall follow the same model.

---

# Session Revocation

Trading Sessions SHALL immediately terminate when:

- User logs out (optional, configurable)
- Trading Secret changes
- Password changes
- User explicitly locks trading
- Administrator revokes trading
- Broker authorization becomes invalid (configurable)
- Security policy requires re-authentication

Revocation SHALL invalidate all outstanding trading authorizations.

---

# Concurrent Sessions

The platform SHALL support configurable concurrent Trading Sessions.

Possible policies include:

- Single active Trading Session
- Multiple sessions per user
- Multiple trusted devices
- Administrator-defined limits

The default implementation may permit one active Trading Session per user.

---

# Audit Requirements

Every Trading Session event SHALL be recorded.

Examples include:

- Trading Unlock Requested
- MFA Verified
- Trading Secret Verified
- Trading Session Created
- Trading Session Expired
- Trading Session Revoked
- Trading Unlock Failed

Audit history SHALL remain immutable.

---

# Monitoring

Security monitoring SHALL expose metrics including:

- Active Trading Sessions
- Failed Unlock Attempts
- Average Session Duration
- Session Expiry Count
- Revocation Count
- MFA Failure Rate

---

# Design Constraints

Trading Unlock SHALL NOT:

- replace application authentication
- replace permissions
- replace operational authorization
- remain permanently active
- bypass security policies

Trading Unlock SHALL always require:

- authenticated identity
- valid permissions
- successful MFA
- successful Trading Secret verification

---

# Future Compatibility

The Trading Unlock architecture has been designed to support:

- Hardware Security Keys
- WebAuthn
- Biometrics
- Enterprise MFA
- Risk-based Authentication
- Trusted Devices
- Adaptive Authentication

These capabilities shall extend the Trading Unlock model without requiring architectural redesign.

# Section 4 – Broker Credential Management

## Purpose

This section defines how StoX securely stores, protects, manages, and uses broker credentials.

Broker credentials provide access to external trading accounts and therefore require stronger protection than ordinary application credentials.

This specification governs:

- Broker authentication
- Credential storage
- Session management
- Token refresh
- Credential rotation
- Broker authorization

---

# Objectives

Broker Credential Management SHALL:

- protect broker credentials
- isolate broker authentication
- prevent credential leakage
- support secure token refresh
- minimize user interaction
- support multiple brokers
- maintain complete audit history

---

# Architectural Principles

## Principle 1 – Broker Independence

Each broker manages its own authentication mechanism.

StoX shall abstract broker-specific authentication behind the Broker Adapter.

---

## Principle 2 – Session Separation

The following sessions SHALL remain completely independent.

Application Session

↓

Trading Session

↓

Broker Session

Expiration of one SHALL NOT automatically invalidate the others unless explicitly configured.

---

## Principle 3 – Credential Isolation

Broker credentials SHALL never be accessible outside the Broker Security subsystem.

Execution Engine shall never access raw credentials.

UI shall never access raw credentials.

Logging shall never expose credentials.

---

# Credential Types

The architecture supports the following credential types.

Examples:

- API Key
- API Secret
- OAuth Access Token
- Refresh Token
- Session Token
- Certificate (future)
- Hardware Key (future)

Broker implementations may require any combination.

---

# Broker Session

## Purpose

A Broker Session represents an authenticated connection between StoX and a Broker.

A Broker Session is distinct from both:

- Application Session
- Trading Session

---

# Broker Session Lifecycle

Configured

↓

Connecting

↓

Authenticated

↓

Ready

↓

Executing

↓

Expired

↓

Refreshing

↓

Ready

or

Failed

---

# Broker Session States

## Configured

Broker credentials exist but authentication has not started.

---

## Connecting

Authentication request is in progress.

---

## Authenticated

Broker has successfully authenticated StoX.

---

## Ready

Broker is ready to accept requests.

---

## Executing

Broker is actively processing execution requests.

---

## Expired

Broker authorization has expired.

Execution is suspended until authentication succeeds.

---

## Refreshing

Credential refresh is in progress.

---

## Failed

Authentication cannot be re-established.

Manual intervention is required.

---

# Credential Storage

Broker credentials SHALL:

- be encrypted at rest
- never be stored in plaintext
- never be logged
- never be returned by APIs
- never appear in browser storage

Only the Broker Security subsystem may decrypt credentials.

---

# Credential Rotation

Credential rotation SHALL support:

- manual rotation
- scheduled rotation (future)
- emergency rotation

Rotation SHALL invalidate previous credentials.

All rotation events SHALL be audited.

---

# Credential Revocation

Credentials SHALL be revoked when:

- broker access is removed
- administrator revokes access
- user disconnects broker
- compromise is suspected

Revocation SHALL terminate all active Broker Sessions.

---

# Token Refresh

Where supported by the broker:

Broker Adapters SHALL automatically refresh access tokens.

Refresh SHALL occur before expiry whenever possible.

Execution SHALL pause during refresh if required.

Refresh failures SHALL NOT expose credentials.

---

# Multiple Brokers

The architecture SHALL support multiple Broker Connections.

Each Broker Connection maintains:

- independent credentials
- independent Broker Session
- independent health status

Failure of one Broker Session SHALL NOT affect others.

---

# Broker Authorization

Before execution, Broker Session SHALL satisfy:

- authenticated
- not expired
- healthy
- capability available

Execution SHALL NOT proceed otherwise.

---

# Sensitive Operations

The following operations require an Active Trading Session and elevated authorization:

- Add Broker
- Remove Broker
- Rotate Credentials
- Change API Keys
- Reconnect Broker
- Force Session Refresh

These operations SHALL require explicit user confirmation.

---

# Broker Health Monitoring

Every Broker Connection SHALL expose:

- Authentication Status
- Session Expiry
- Last Successful Authentication
- Refresh Status
- Connectivity Status
- API Availability
- Rate Limit Status

Monitoring SHALL consume this information.

---

# Failure Handling

Examples include:

Authentication Failure

↓

Broker Session Failed

↓

Execution Suspended

↓

User Notification

↓

Administrator Audit

The Broker Adapter SHALL never silently ignore authentication failures.

---

# Audit Requirements

Every broker security event SHALL be recorded.

Examples include:

- Broker Added
- Broker Removed
- Authentication Started
- Authentication Successful
- Authentication Failed
- Token Refreshed
- Credential Rotated
- Broker Session Expired
- Broker Session Revoked

Audit records SHALL never contain credential values.

---

# Design Constraints

Broker credentials SHALL:

- remain encrypted
- remain isolated
- never leave the Broker Security subsystem
- never appear in client applications
- never bypass Trading Unlock
- never bypass Operational Authorization

---

# Future Compatibility

The Broker Credential architecture has been designed to support:

- OAuth-based brokers
- API Key-based brokers
- Certificate-based authentication
- Hardware security modules
- Cloud secret managers
- Multiple broker accounts
- Institutional broker connectivity

Future authentication mechanisms shall extend this architecture without redesign.

# Section 5 – Automation Authorization

## Purpose

This section defines how StoX authorizes automated trading.

Unlike interactive trading, automated trading executes without the user actively using the application.

Automation therefore requires an independent authorization model.

---

# Objectives

Automation Authorization SHALL:

- prevent unauthorized automated trading
- require explicit user consent
- remain independent of browser sessions
- support progressive automation
- remain fully auditable
- support future regulatory requirements

---

# Design Principles

Automation SHALL:

- never inherit browser sessions
- never inherit login sessions
- never inherit Trading Sessions
- require explicit enablement
- remain independently revocable

Automation operates as a trusted system actor rather than an interactive user.

---

# Automation Authorization Flow

User Login

↓

Trading Unlock

↓

Automation Configuration

↓

Explicit Confirmation

↓

Automation Authorization Created

↓

Scheduler

↓

Execution Engine

↓

Broker

---

# Automation Authorization

Automation Authorization represents permission for StoX to execute trades on behalf of the user without requiring interactive approval.

Automation Authorization SHALL be:

- portfolio-specific
- broker-specific
- strategy-specific
- revocable
- auditable

---

# Automation States

Disabled

↓

Configured

↓

Pending Verification

↓

Authorized

↓

Paused

↓

Suspended

↓

Revoked

---

## Disabled

Automation has not been configured.

---

## Configured

Automation settings exist but authorization has not been granted.

---

## Pending Verification

User has requested automation.

Additional security verification is required.

---

## Authorized

Automation is permitted to execute according to the configured Execution Mode.

---

## Paused

Automation is temporarily paused.

No new executions occur.

Existing executions continue until completion.

---

## Suspended

Automation has been suspended automatically.

Examples:

- Broker unavailable
- Security event
- Excessive failures
- Kill Switch activated

---

## Revoked

Automation authorization has been permanently revoked.

Reconfiguration is required.

---

# Progressive Automation Levels

Automation maturity follows progressive stages.

Level 0

Recommendation Only

↓

Level 1

Manual Execution

↓

Level 2

Semi-Automatic

↓

Level 3

Supervised Automatic

↓

Level 4

Fully Automatic

Higher levels require stronger authorization.

---

# Authorization Requirements

Automation SHALL require:

- Authenticated User
- Trading Permission
- Active Trading Unlock
- Successful MFA
- Trading Secret Verification
- Broker Connected
- Broker Authenticated
- Live Trading Enabled

All requirements must succeed before authorization is granted.

---

# User Maturity

Fully Automatic mode SHALL require demonstration of responsible system usage.

Typical eligibility criteria may include:

- Minimum observation period
- Successful Semi-Automatic executions
- Stable broker connectivity
- No recent security incidents

The exact criteria shall be configurable.

---

# Broker Trust

Automation additionally evaluates Broker Trust.

Examples include:

- Successful authentication history
- Successful synchronization history
- Successful manual executions
- Successful semi-automatic executions
- Stable connectivity
- Low failure rate

Low-trust brokers SHALL NOT permit Fully Automatic execution.

---

# Automation Scope

Automation Authorization SHALL specify:

- Portfolio
- Strategy
- Broker
- Execution Mode
- Risk Policy
- Execution Policy
- Maximum Daily Exposure
- Maximum Order Value
- Maximum Portfolio Exposure

Automation SHALL NOT operate outside its authorized scope.

---

# Automation Expiry

Automation Authorization may expire.

Expiry conditions may include:

- Time-based expiration
- Password change
- Trading Secret change
- Broker credential rotation
- Extended inactivity
- Administrator action

Expired authorizations require re-verification.

---

# Automation Suspension

Automation SHALL suspend automatically when:

- Kill Switch activated
- Broker unavailable
- Broker authentication fails
- Security policy violation
- Repeated execution failures
- Risk Policy violation

Suspension SHALL preserve all execution history.

---

# Manual Override

Users may manually override automation.

Examples include:

- Pause Automation
- Resume Automation
- Execute Manually
- Disable Automation

Manual overrides SHALL always take precedence.

---

# Audit Requirements

Automation events SHALL be recorded.

Examples include:

- Automation Enabled
- Automation Disabled
- Authorization Granted
- Authorization Revoked
- Automatic Suspension
- Manual Override
- Automatic Resume

---

# Monitoring

Automation metrics SHALL include:

- Automation Uptime
- Automatic Executions
- Manual Overrides
- Suspensions
- Resumptions
- Authorization Expiry
- Failure Rate

---

# Design Constraints

Automation SHALL:

- remain independent of browser sessions
- remain independent of Trading Sessions
- require explicit authorization
- remain fully auditable
- never bypass Risk Policies
- never bypass Operational Authorization

---

# Future Compatibility

Automation Authorization has been designed to support:

- Multiple brokers
- Multiple portfolios
- Distributed schedulers
- Cloud execution
- Institutional workflows
- Regulatory approval chains

These capabilities shall extend the authorization model without requiring redesign.

# Section 6 – Emergency Controls & Kill Switches

## Purpose

This section defines the emergency mechanisms available to immediately prevent unintended or unsafe trading activity.

Emergency Controls are designed to minimize financial risk while preserving system integrity and auditability.

These controls operate independently of ordinary trading permissions.

---

# Objectives

Emergency Controls SHALL:

- stop unsafe trading immediately
- protect portfolio assets
- preserve audit history
- support rapid recovery
- remain easy to operate under stress
- never compromise data integrity

---

# Design Principles

Emergency Controls SHALL:

- fail safe
- act immediately
- remain independently auditable
- be simple to understand
- require minimal user interaction
- support progressive recovery

---

# Emergency Control Hierarchy

Global Kill Switch

↓

Broker Kill Switch

↓

Portfolio Kill Switch

↓

Automation Pause

↓

Execution Cancellation

Higher-level controls override lower-level controls.

---

# Global Kill Switch

## Purpose

Immediately prevents all new live trading activity across StoX.

The Global Kill Switch is intended for severe incidents.

Examples include:

- security compromise
- major software defect
- broker-wide outage
- regulatory halt
- administrator intervention

---

# Behaviour

When enabled:

- No new Execution Runs begin.
- Existing Execution Runs continue only if configured.
- Automation is suspended.
- Broker synchronization may continue.
- Read-only operations remain available.

The exact behaviour shall be configurable.

---

# Broker Kill Switch

## Purpose

Disables trading through one Broker.

Examples:

- Zerodha unavailable
- Broker maintenance
- Broker authentication failure

Other brokers remain unaffected.

---

# Portfolio Kill Switch

## Purpose

Disables trading for one Portfolio.

Examples:

- suspected compromise
- user request
- portfolio under review
- maintenance

Other portfolios remain unaffected.

---

# Automation Pause

Automation Pause temporarily suspends automated execution.

Manual execution remains available.

Automation Pause does not revoke Automation Authorization.

---

# Execution Cancellation

Execution Cancellation attempts to stop an Execution Run before broker submission.

Execution already accepted by the broker cannot be cancelled by StoX.

The Broker Adapter may attempt broker-side cancellation where supported.

---

# Manual Trading Lock

Users may voluntarily lock trading.

Examples include:

- travelling
- vacation
- suspected compromise
- extended inactivity

Unlocking requires a new Trading Unlock.

---

# Automatic Emergency Triggers

The system may automatically activate emergency controls.

Examples include:

- repeated authentication failures
- repeated broker failures
- excessive execution failures
- unusual trading behaviour
- security policy violations
- administrator-defined rules

Automatic activation SHALL always generate audit records.

---

# Notification Requirements

Emergency events SHALL generate high-priority notifications.

Examples include:

Global Kill Switch Enabled

Broker Disabled

Portfolio Locked

Automation Suspended

Execution Cancelled

Security Lock Activated

Notification channels are defined separately.

---

# Recovery

Recovery shall occur through explicit user or administrator action.

Examples include:

- resume automation
- unlock portfolio
- reconnect broker
- disable kill switch
- reauthorize automation

Recovery SHALL NOT occur automatically unless explicitly configured.

---

# Operational Authorization

Emergency Controls SHALL participate in Operational Authorization.

Before execution, Operational Authorization SHALL verify:

- Global Kill Switch inactive
- Broker Kill Switch inactive
- Portfolio Kill Switch inactive
- Automation authorization valid
- Trading Session active
- Broker Session active

Failure of any check immediately blocks execution.

---

# Audit Requirements

Every Emergency Control event SHALL be recorded.

Examples include:

- activation
- deactivation
- initiating user
- initiating subsystem
- reason
- affected scope
- duration

Audit history SHALL be immutable.

---

# Monitoring

Monitoring SHALL expose:

- active kill switches
- emergency activations
- automatic activations
- recovery events
- execution blocks
- suspension durations

Operational dashboards SHALL prominently display active Emergency Controls.

---

# Design Constraints

Emergency Controls SHALL:

- never modify completed trades
- never alter historical records
- never bypass audit logging
- never bypass security policies

Emergency Controls SHALL affect only future execution activity unless broker-side cancellation is possible.

---

# Future Compatibility

The Emergency Control architecture supports future capabilities including:

- regulatory trading halts
- exchange-level circuit breakers
- broker failover
- institutional approval workflows
- compliance interventions

Future capabilities shall extend the existing control hierarchy without redesign.

# Section 7 – Security Audit & Event Model

## Purpose

This section defines how security-related events are recorded, stored, and monitored within the Live Trading subsystem.

The objective is to ensure that every security-sensitive operation can be reconstructed, investigated, and explained.

Security Audit provides accountability, forensic evidence, operational visibility, and regulatory readiness.

---

# Objectives

The Security Audit subsystem SHALL:

- record every security-sensitive action
- preserve immutable history
- support forensic investigation
- support operational monitoring
- support security analytics
- support future regulatory requirements

---

# Design Principles

Security Audit SHALL:

- be append-only
- be immutable
- be chronological
- be searchable
- be correlated across subsystems
- never affect execution performance

---

# Security Events

Security events include, but are not limited to:

Authentication

Authorization

Trading Unlock

Broker Authentication

Broker Credential Rotation

Automation Authorization

Kill Switch Activation

Permission Changes

Administrative Actions

Security Policy Changes

Emergency Controls

Every event SHALL be categorized.

---

# Event Classification

Events SHALL be classified by severity.

Examples:

Information

Warning

Security

Critical

Emergency

Severity determines monitoring and notification behaviour.

---

# Event Metadata

Every security event SHALL include:

- Event Identifier
- Event Type
- Timestamp
- User
- Portfolio
- Broker (if applicable)
- Session Identifier
- Trading Session Identifier
- Automation Authorization Identifier (if applicable)
- Source Component
- Correlation Identifier
- Severity
- Outcome

---

# Event Categories

Examples include:

## Authentication Events

- Login Successful
- Login Failed
- Logout
- Password Changed

---

## Authorization Events

- Permission Granted
- Permission Revoked
- Access Denied

---

## Trading Unlock Events

- Unlock Requested
- MFA Successful
- Trading Secret Verified
- Unlock Failed
- Trading Session Created
- Trading Session Expired

---

## Broker Events

- Broker Connected
- Broker Authentication Failed
- Session Refreshed
- Credentials Rotated

---

## Automation Events

- Automation Enabled
- Automation Disabled
- Authorization Granted
- Authorization Revoked
- Automation Suspended

---

## Emergency Events

- Kill Switch Activated
- Kill Switch Cleared
- Portfolio Locked
- Broker Disabled
- Manual Trading Locked

---

## Administrative Events

- User Created
- User Disabled
- Security Policy Updated
- Trading Permission Changed
- Broker Configuration Changed

---

# Correlation

Security events SHALL support correlation.

Examples:

Execution Run

↓

Trading Session

↓

Broker Session

↓

Execution

↓

Audit Events

Investigators SHALL be able to reconstruct complete execution history using correlation identifiers.

---

# Event Retention

Security events SHALL remain available according to configurable retention policies.

Historical events may be archived but SHALL remain retrievable.

Automatic deletion of audit history is prohibited unless explicitly configured by administrative policy.

---

# Audit Integrity

Audit records SHALL:

- be append-only
- preserve original timestamps
- preserve original actors
- preserve original outcomes

Modification of existing audit records is prohibited.

---

# Monitoring Integration

Security Audit SHALL publish metrics including:

- failed login rate
- failed MFA rate
- trading unlock failures
- authorization failures
- broker authentication failures
- automation suspensions
- kill switch activations

Monitoring dashboards SHALL consume these metrics.

---

# Notification Integration

Critical security events SHALL trigger notifications.

Examples:

- repeated failed logins
- repeated MFA failures
- broker credential rotation
- trading unlock failures
- automation authorization revoked
- kill switch activated

Notification routing is defined separately.

---

# Security Analytics

The architecture supports future security analytics including:

- anomaly detection
- unusual login patterns
- suspicious trading behaviour
- repeated authorization failures
- geographic anomalies
- impossible travel detection
- privilege escalation detection

These capabilities extend the existing event model.

---

# Design Constraints

Security Audit SHALL:

- never delay execution
- never expose sensitive credentials
- never bypass privacy policies
- never modify historical records

Audit remains an observational subsystem.

---

# Future Compatibility

The Security Audit architecture supports future capabilities including:

- SIEM integration
- external audit systems
- regulatory reporting
- enterprise compliance
- real-time threat detection
- security dashboards

These capabilities shall extend the audit model without architectural redesign.

# Section 8 – Future Compatibility

## Purpose

This section defines the long-term evolution goals of the Security architecture.

The objective is to ensure that future capabilities can be introduced without redesigning the existing security model.

---

# Design Philosophy

The Security architecture has been designed to be:

- extensible
- technology-independent
- broker-independent
- identity-provider independent
- authentication-method independent

Future security capabilities should extend existing abstractions rather than replace them.

---

# Future Authentication Methods

The architecture supports future authentication mechanisms including:

- WebAuthn
- FIDO2 Security Keys
- Biometrics
- Enterprise Single Sign-On
- Passkeys
- Certificate-based Authentication

These methods may replace or complement existing login mechanisms.

---

# Future Trading Unlock Methods

Trading Unlock currently uses:

- Multi-Factor Authentication
- Trading Secret

Future implementations may support:

- Hardware Security Keys
- Biometrics
- Passkeys
- Adaptive Authentication
- Risk-based Authentication

The Trading Unlock workflow remains unchanged.

---

# Future Permission Models

The current capability-based permission model may be extended to support:

- Attribute-Based Access Control (ABAC)
- Policy-Based Access Control (PBAC)
- Organization-level permissions
- Team-based portfolios
- Delegated portfolio management
- Temporary privilege elevation

---

# Future Automation Controls

Automation Authorization has been designed to support:

- AI-supervised execution
- Human approval chains
- Institutional approval workflows
- Multi-stage execution authorization
- Regulatory approval requirements

---

# Future Broker Security

Future Broker implementations may support:

- OAuth
- Hardware authentication
- Certificate authentication
- Secret managers
- Multi-account authentication
- Institutional broker gateways

The Broker Abstraction Layer isolates these changes.

---

# Future Operational Controls

Operational Controls may expand to include:

- Compliance Hold
- Regulatory Suspension
- Maintenance Windows
- Scheduled Trading Freeze
- Market Holiday Policies
- Portfolio Quarantine

These controls extend the existing Operational Control framework.

---

# Future Monitoring

Future monitoring capabilities may include:

- Security dashboards
- Threat detection
- Behaviour analytics
- Machine learning anomaly detection
- Automated incident response

---

# Future Compliance

Future regulatory support may include:

- SEBI requirements
- MiFID
- SEC reporting
- Audit exports
- Regulatory retention policies

Compliance extensions shall not require redesign of the security architecture.

---

# Architectural Stability

Future enhancements SHALL preserve the following architectural principles:

- Identity remains independent.
- Permissions remain capability-based.
- Operational Authorization remains runtime-based.
- Trading Unlock remains independent.
- Automation remains independently authorized.
- Broker authentication remains isolated.
- Audit remains immutable.

These principles form the long-term foundation of the Security subsystem.
