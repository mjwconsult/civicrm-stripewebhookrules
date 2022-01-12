<?php

require_once 'stripewebhookrules.civix.php';
// phpcs:disable
use CRM_Stripewebhookrules_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function stripewebhookrules_civicrm_config(&$config) {
  _stripewebhookrules_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function stripewebhookrules_civicrm_xmlMenu(&$files) {
  _stripewebhookrules_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function stripewebhookrules_civicrm_install() {
  _stripewebhookrules_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function stripewebhookrules_civicrm_postInstall() {
  _stripewebhookrules_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function stripewebhookrules_civicrm_uninstall() {
  _stripewebhookrules_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function stripewebhookrules_civicrm_enable() {
  _stripewebhookrules_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function stripewebhookrules_civicrm_disable() {
  _stripewebhookrules_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function stripewebhookrules_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _stripewebhookrules_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function stripewebhookrules_civicrm_managed(&$entities) {
  _stripewebhookrules_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function stripewebhookrules_civicrm_angularModules(&$angularModules) {
  _stripewebhookrules_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function stripewebhookrules_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _stripewebhookrules_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function stripewebhookrules_civicrm_entityTypes(&$entityTypes) {
  _stripewebhookrules_civix_civicrm_entityTypes($entityTypes);
}

function stripewebhookrules_civicrm_webhook_eventNotMatched(string $type, Object $object, string $code, array &$result) {
  if ($type !== 'stripe') {
    return;
  }
  if (!($object instanceof CRM_Core_Payment_StripeIPN)) {
    return;
  }

  /** @var CRM_Core_Payment_StripeIPN $object */

  switch ($code) {
    case 'contribution_not_found':
      // contribution_not_found is likely to happen if trxn_id is not set to stripe invoice or charge ID.
      // trxn_id should always be set but there seem to be cases when it is not.

      // We are only going to try to find a contribution for the "invoice.payment_succeeded" webhook.
      if (!in_array($object->getEventType(), ['invoice.finalized', 'invoice.payment_succeeded', 'invoice.payment_failed'])) {
        return;
      }

      if (empty($object->getContributionRecurID())) {
        \Civi::log()->error("stripewebhookrules: {$code} : no contributionrecurid set.");
        return;
      }
      // We need the period_start and period_end to work out if a pending contribution is within the date range for an invoice.
      // There is no fixed "invoice date" so this seems to be the best compromise.
      if (empty($object->getData()->object->period_start) || empty($object->getData()->object->period_end)) {
        \Civi::log()->error("stripewebhookrules: {$code} : period_start or period_end not set for invoice");
        return;
      }
      // API needs them in YmdHis format. They come in as timestamp.
      $periodStart = date('Ymd', $object->getData()->object->period_start) . '000000';
      $periodEnd = date('Ymd', $object->getData()->object->period_end) . '235959';

      // Look for a contribution:
      //   - That is linked to the recurring contribution
      //   - That is "Pending"
      //   - That is NOT a template
      //   - That has a receive_date within the invoice date range
      //   - trxn_id has not been set
      //   - That has the most recent `receive_date`. If we already completed the next contribution we won't match..
      //       ..possibly we don't need this but it adds extra "safety" to the matching by reducing the scope for error.
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('contribution_recur_id', '=', $object->getContributionRecurID())
        ->addWhere('is_template', '=', FALSE)
        ->addWhere('contribution_status_id:name', '=', 'Pending')
        ->addWhere('receive_date', 'BETWEEN', [$periodStart, $periodEnd])
        ->addWhere('trxn_id', 'IS NULL')
        ->addOrderBy('receive_date', 'DESC')
        ->execute()
        ->first();
      if (!empty($contribution)) {
        // We found a contribution so assign it back to the result so it can be used by the calling code.
        $contributionID = $contribution['id'];
        $trxnID = $object->getStripeChargeID() ?? $object->getStripeInvoiceID() ?? NULL;
        if (!empty($trxnID)) {
          // Update the found contribution with the invoice/charge ID.
          $contribution = \Civi\Api4\Contribution::update(FALSE)
            ->addWhere('id', '=', $contributionID)
            ->addValue('trxn_id', $trxnID);
          \Civi::log()->info("stripewebhookrules: Adding missing trxnID {$trxnID} to contribution {$contributionID}");

          // @fixme: On 5.35.2 Pending don't seem to update to Failed - force it here.
          if ($object->getEventType() === 'invoice.payment_failed') {
            $contribution->addValue('contribution_status_id:name', 'Failed');
            \Civi::log()->info("stripewebhookrules: Updating contribution {$contributionID} to Failed");
          }
          $contribution->execute()->first();
        }
        $result['contribution'] = $contribution;

      }
      break;
  }

}
