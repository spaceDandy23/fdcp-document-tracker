
# FDCP Document Tracking System

## Tech Stack
- Laravel
- Livewire
- Tailwind CSS
- MySQL
- FDCP SSO via Laravel Passport OAuth2

## Architecture
- Laravel monolithic application.
- Business logic lives in dedicated Action classes.
- Controllers and Livewire components remain thin.
- Laravel Policies enforce authorization.
- Database transactions protect document state changes.

## Document Routing
- Free routing across divisions.
- No fixed workflow stages.
- One assigned handler per document.
- Only the current authorized handler can forward.
- Ordinary forwarding requires acknowledgment.
- A recipient can decline with a required reason.
- Declining restores responsibility to the sender.
- Acknowledged documents can be returned for revision.
- Revision returns are automatically assigned to the recipient.
- Every movement and significant action is recorded.

## Document Versioning
- Documents have permanent tracking numbers.
- Revisions create immutable document versions.
- Previous versions must never be overwritten.
- Each document route references the exact version forwarded.
- Supporting attachments are stored separately.

## Access Control
- Authentication uses the existing FDCP SSO.
- Users and divisions are synchronized locally.
- Creators, current handlers, and previous handlers can view relevant documents.
- Division heads can view documents originating from or routed through their divisions.
- Administrators have authorized oversight access.

## Document States
- draft
- pending_acknowledgment
- in_progress
- revision_requested
- completed
- cancelled

## Core Models
- Division
- User
- DocumentType
- Document
- DocumentVersion
- DocumentRoute
- DocumentAction
- DocumentAttachment

## Testing Requirements
- Test valid and invalid state transitions.
- Test authorization and document visibility.
- Test acknowledgment and decline behavior.
- Test document version preservation.
- Test duplicate forwarding and concurrency protection.
