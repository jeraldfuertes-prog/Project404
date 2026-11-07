# Appendix A — BillGuard System Proposal (DRAFT / NOT YET APPROVED)

**Course:** IT 107 – Information Assurance and Security I  
**Project owner:** __________________  
**Student number:** __________________ *(do not commit a real student number to this public repository)*  
**Date submitted:** __________________  

## System name

**BillGuard – Secure Household Utility Bill Monitoring System**

## Who uses it

- **Household user:** An adult resident who tracks utility bills and payments for a household.
- **System administrator:** A designated operator who monitors security events and maintains security safeguards. Admins do **not** routinely access users' private utility account numbers or bill records.

## What it stores

Required registration information (ID, full name, birthdate, six address fields, email, username and private hashed authentication data); account-verification and audit records; household utility account number, utility type, service start date, a household utility budget, electricity/water/internet bill records, due dates, and payment history.

## Why accounts need protecting

BillGuard holds **personal and financial information**. Utility account numbers can identify a household's provider account; addresses and billing/payment records can expose private household information. Access must be restricted to the household account owner. Authentication, authorization, email OTP, secure sessions and monitoring are therefore essential.

## Sensitive field to encrypt (Protection 13)

**Utility Account Number**, with AES-256 using a fresh random IV for each value; the backend stores ciphertext, IV and authentication tag. It must only be decrypted when the authenticated owner is allowed to view it. Passwords, OTPs and security-question answers are **hashed**, never encrypted for later recovery.

## Four system-specific registration fields (proposed)

These are **in addition** to all mandatory core registration fields in Section 4.1 of the student handout. Each one will have client-side **and** server-side validation and a field-specific error message.

| Field | Kind | Required? | Server validation rule | Exact error message |
| --- | --- | --- | --- | --- |
| Utility Account Number | Formatted numeric identifier (text input) | Required | Strip no characters automatically; 8–12 ASCII digits only; must be encrypted at rest | Utility Account Number must contain 8 to 12 digits. |
| Primary Utility Type | Dropdown | Required | Exactly Electricity, Water, or Internet; reject other submitted values | Please select a valid utility type. |
| Service Start Date | Date | Required | Real YYYY-MM-DD date; cannot be in the future | Service Start Date must be a valid date that is not in the future. |
| Monthly Utility Budget | Decimal amount | Required | Numeric PHP amount greater than 0.00 and at most 999999.99, with up to 2 decimal places | Monthly Utility Budget must be greater than ₱0.00 and no more than ₱999,999.99. |

**Note:** The chosen utility account number must be one the resident is authorized to manage. No real billing provider integration or automatic payments are proposed. User-entered bill amounts and payment records will be editable only by their authenticated owner.

## Privacy justification for these four fields

| Field | Why it is collected |
| --- | --- |
| Utility Account Number | Identify the resident's selected utility account for bill organization |
| Primary Utility Type | Configure the household's initial utility category |
| Service Start Date | Establish the start of tracked utility service |
| Monthly Utility Budget | Calculate budget comparison for monthly bills |

The Privacy Notice before launch must identify all collected personal information, purposes, storage and security, retention period, recipient/sharing rules, and an actual privacy contact. The required consent checkbox starts unchecked; the server must store the consent timestamp. Do not launch registration before this is complete and the proposal is approved.

## Instructor's decision (Checkpoint 1)

- [ ] Approved
- [ ] Revise and resubmit

**Instructor signature:** __________________  
**Approval date:** __________________

*Do not use this draft as evidence of approval. The printed/signed Appendix A from the handout remains the submission form.*
