/**
 * @file
 * On a tool page that has several machines, the generic "Report to Staff"
 * button (rendered by the report_asset_issue view with the tool's nid) would
 * file the report against the tool rather than a machine. Point it at the
 * machine list instead, where each machine has its own report link.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.assetStatusUnits = {
    attach: function (context) {
      var parentNid = drupalSettings.assetStatus && drupalSettings.assetStatus.unitsParentNid;
      var list = document.getElementById('asset-status-units');
      if (!parentNid || !list) {
        return;
      }
      var links = context.querySelectorAll('a[href*="asset_nid=' + parentNid + '"]');
      Array.prototype.forEach.call(links, function (link) {
        if (link.dataset.assetUnitsRedirected) {
          return;
        }
        link.dataset.assetUnitsRedirected = '1';
        link.setAttribute('href', '#asset-status-units');
        link.textContent = Drupal.t('Report to Staff — choose the machine');
        link.addEventListener('click', function () {
          list.classList.add('asset-units--highlight');
          window.setTimeout(function () {
 list.classList.remove('asset-units--highlight'); }, 2500);
        });
      });
    }
  };
})(Drupal, drupalSettings);
