# stripewebhookrules

This adds extra "rules" for processing Stripe webhooks using the webhookEventNotMatched hook.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* Payment Shared 1.2.2+
* Stripe 6.7+

## Installation

See: https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension

If you have any problems just disable the extension.

If you want to "fix" specific webhooks you can enable this extension, process some webhooks and then disable it again.

## What it does?

When a `stripe.contribution_not_matched` event is triggered for webhookEventNotMatched we use the following logic to
try to match a contribution:
```
      // contribution_not_found is likely to happen if trxn_id is not set to stripe invoice or charge ID.
      // trxn_id should always be set but there seem to be cases when it is not.

      // Look for a contribution:
      //   - That is linked to the recurring contribution
      //   - That is "Pending"
      //   - That is NOT a template
      //   - That has a receive_date within the invoice date range
      //   - That has the most recent `receive_date`. If we already completed the next contribution we won't match..
      //       ..possibly we don't need this but it adds extra "safety" to the matching by reducing the scope for error.
```
