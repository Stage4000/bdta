Custom CRM Functional Specification
1. System Overview
This CRM is a custom, mobile-first system designed to support a solo service-based business offering in-home services, consultations, packages, and group classes. The system prioritizes speed, clarity, and flexibility over rigid automation, allowing the business owner to override rules as needed while still benefiting from guardrails and automation.
The CRM will replace Freelance With Moxie and consolidate client management, scheduling, contracts, forms, invoicing, and basic reporting into a single platform.
There will be no client login system in Phase 1. All client interactions will occur via secure, direct-access links (booking, forms, contracts, quotes, invoices).

2. Core Design Principles
Mobile-first admin experience (fully functional from a phone)
Extremely fast manual booking flow
Flexible rule enforcement with admin override
Clear, centralized client history (“everything in one place”)
Minimal friction for clients

3. Core Objects & Data Models
3.1 Client Profile
Each client has a single profile that acts as the central hub.
Client Profile Includes:
Contact information
Physical address
Linked pets (multiple allowed)
Appointment history (past)
Upcoming appointments
Signed contracts
Quotes (sent, viewed, accepted, declined, expired)
Invoices (paid, unpaid, partial, credited)
Forms submitted
Internal-only notes (timestamped)
Uploaded files (optional)
Client to-dos (checkbox + note + optional due date)
Internal notes are always private, timestamped, and attached to the client (not pet-specific).

3.2 Pet Profiles
Each client may have multiple pets.
Pet Profile Fields:
Name
Species
Breed
Date of birth / age
Source (where the pet was acquired)
Length of ownership
Spay/neuter status
Vaccine status
Behavior notes
Medical notes
Training history (linked appointments)
Appointments may involve one or multiple pets.

3.3 Appointment Types
Appointments are controlled by appointment types, which define behavior and rules.
Each appointment type controls:
Duration
Buffer before
Buffer after
Booking availability windows
Advance booking limits (configurable)
Required forms
Required contracts
Invoice behavior
Whether credits are consumed
Whether auto-invoicing occurs
Examples:
Consultation
Meet & Greet
Coaching Session
Group Class
Group classes are included in Phase 1.

4. Scheduling & Booking
4.1 Client Booking
Clients book via:
Website booking links
Direct booking links sent manually
Booking flow:
Client selects appointment type
System checks availability (CRM + Google Calendar)
Client enters:
Pets involved
Reason for appointment
Brief pet history
System enforces required forms/contracts (if applicable)
Appointment is confirmed
Some appointment types (e.g., consultations) require forms before booking.

4.2 Manual Booking (Admin)
Manual booking is optimized for speed and mobile use.
Priorities:
Speed (minimal taps)
Ability to override all rules
One-handed phone use
Optional visibility into client history
Manual booking supports:
Booking repeat clients instantly
Bypassing form/contract requirements
Viewing real-time availability
Booking multiple pets

4.3 Buffers & Travel Time
Buffers before and after appointments
Buffer duration varies by appointment type
Travel-time-based buffers are a supported enhancement if technically feasible

5. Google Calendar Integration
Two-way sync with a single Google Calendar
CRM checks Google Calendar for availability
CRM pushes new and updated appointments to Google Calendar
Synced fields:
Client name
Appointment type
Location
Internal notes do not sync.

6. Forms System
6.1 Form Templates
Reusable form templates
Forms may be required:
Once
Once per year
Per appointment type
Optional features (nice-to-have):
Conditional logic
File uploads
6.2 Internal Forms
Session notes
Behavior assessments
Training plans
Rules:
Session notes and assessments are tied to appointments
Training plans are versioned over time
Internal forms are searchable

7. Contracts & Quotes
7.1 Contracts
Template-based contracts
Clients typically sign once per year per service type
Contracts are signable electronically
7.2 Quotes
Quotes include pricing terms only
Accept / Decline buttons (no signature required)
Quotes may expire after X days
Multiple quotes per client allowed
Optional:
Accepted quotes can convert to invoices
7.3 Tracking
Track:
Viewed
Accepted
Declined
Expired

8. Invoicing, Payments & Credits
8.1 Invoices
Supports:
One-time invoices
Packages (multiple sessions)
Deposits
Partial payments
Invoices are generally due after sessions, except where configured otherwise.
8.2 Payments
Stripe integration for card payments
Manual payment recording for:
Cash
Check
Payment method selection
Notes field per payment
Partial payments allowed
Receipts generated for all payments (including offline)
8.3 Credits (Session-Based)
Session credits tracked per client
Credits may:
Expire or not expire (configurable)
Be manually adjusted by admin
Credits are consumed by applicable appointment types

9. Rules, Guardrails & Overrides
9.1 Booking Enforcement
System enforces rules for specific appointment types:
Required forms before booking
Required contracts before booking
Invoice payment does not block booking by default.
9.2 Overrides
Admin can override all rules at any time
Overrides do not require logging
Admin can always view availability before overriding

10. Notifications & Emails
10.1 Email Templates
Fully customizable email templates
Supports inserting links (website pages, resources, calendar files)
10.2 Client Emails
Booking confirmations
Appointment reminders
Payment receipts
Contract requests
Form requests
Quote notifications
Reminder emails should include a calendar event link clients can add to their own calendar.
10.3 Admin Notifications
Admin receives notifications when:
Appointments are booked
Forms are submitted
Contracts are signed
Quotes are accepted
Email delivery is required; SMS is optional.

11. Dashboard & Reporting
Dashboard includes:
Upcoming sessions
Outstanding invoices
Clients missing required paperwork
Revenue over time

12. Phase 2 / Nice-to-Have Features
Travel-time-based buffers
Conditional form logic
File uploads in forms
Simple accounting module:
Expense entry
Receipt upload
Categorization
Printable/exportable summaries for taxes

13. Phase 1 Must-Haves (Non-Negotiable)
Appointments & scheduling
Two-way Google Calendar sync
One-step intake form → appointment booking
Invoicing & payments
Fully mobile-optimized admin experience
