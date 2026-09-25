<?php

declare(strict_types=1);

namespace Drupal\asset_status\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Multi-unit tools: one tool page, several physical machines.
 *
 * A tool that exists as several identical machines (two 60 W lasers, a row of
 * Prusa printers) keeps ONE item node for everything members read — badge,
 * instructions, manuals, videos, Slack channel, chatbot context — and one thin
 * child item node per machine, the "unit". Units carry what is genuinely per
 * machine: status, maintenance log, serial number, value, end-of-life date,
 * retirement. The parent lists its units in `field_item_set` (the same
 * parent → children direction rooms and zones already use), and a unit is
 * recognised by its `field_item_category` term, "Machine Unit".
 *
 * Nothing here changes for a tool without units. See
 * docs/arch/MULTI_UNIT_TOOLS.md in the site repository for the design.
 */
final class UnitManager {

  /**
   * Label of the item_category term that marks a unit.
   */
  public const UNIT_CATEGORY_LABEL = 'Machine Unit';

  /**
   * Statuses that mean the machine is no longer part of the fleet.
   */
  public const RETIRED_STATUSES = ['Gone'];

  /**
   * Per-request cache of the "Machine Unit" term id (0 = none).
   */
  private ?int $unitTermId = NULL;

  /**
   * Per-request cache of units per parent nid.
   *
   * @var array<int, \Drupal\node\NodeInterface[]>
   */
  private array $unitsOf = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly AssetAvailability $availability,
  ) {}

  /**
   * The tid of the "Machine Unit" category term, or NULL when it does not exist.
   */
  public function unitTermId(): ?int {
    if ($this->unitTermId === NULL) {
      $tid = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.vid', 'item_category')
        ->condition('t.name', self::UNIT_CATEGORY_LABEL)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $this->unitTermId = $tid ? (int) $tid : 0;
    }
    return $this->unitTermId ?: NULL;
  }

  /**
   * TRUE when the node is a unit (one physical machine of a multi-unit tool).
   */
  public function isUnit(NodeInterface $node): bool {
    if ($node->bundle() !== 'item' || !$node->hasField('field_item_category') || $node->get('field_item_category')->isEmpty()) {
      return FALSE;
    }
    $tid = $this->unitTermId();
    return $tid !== NULL && (int) $node->get('field_item_category')->target_id === $tid;
  }

  /**
   * The tool a unit belongs to: the item node whose set lists it.
   */
  public function getParent(NodeInterface $unit): ?NodeInterface {
    $nid = (int) $unit->id();
    if (!$nid) {
      return NULL;
    }
    // Not cached: a unit is created before its parent lists it, and hooks on
    // that first save already ask for the parent.
    $parent_nid = $this->database->select('node__field_item_set', 's')
      ->fields('s', ['entity_id'])
      ->condition('s.field_item_set_target_id', $nid)
      ->condition('s.deleted', 0)
      ->orderBy('s.entity_id')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!$parent_nid) {
      return NULL;
    }
    $parent = $this->entityTypeManager->getStorage('node')->load((int) $parent_nid);
    return $parent instanceof NodeInterface ? $parent : NULL;
  }

  /**
   * Forgets cached unit lists; call after an item node is saved.
   */
  public function resetCache(): void {
    $this->unitsOf = [];
    $this->unitTermId = NULL;
  }

  /**
   * The units of a tool, in set order. Empty for an ordinary tool.
   *
   * @param \Drupal\node\NodeInterface $parent
   *   The tool.
   * @param bool $include_retired
   *   Include units whose status is Gone.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Keyed by nid.
   */
  public function getUnits(NodeInterface $parent, bool $include_retired = FALSE): array {
    $nid = (int) $parent->id();
    if (!$nid || !$parent->hasField('field_item_set') || $parent->get('field_item_set')->isEmpty() || $this->unitTermId() === NULL) {
      return [];
    }
    if (!isset($this->unitsOf[$nid])) {
      $this->unitsOf[$nid] = [];
      foreach ($parent->get('field_item_set')->referencedEntities() as $child) {
        if ($child instanceof NodeInterface && $child->isPublished() && $this->isUnit($child)) {
          $this->unitsOf[$nid][(int) $child->id()] = $child;
        }
      }
    }
    if ($include_retired) {
      return $this->unitsOf[$nid];
    }
    return array_filter($this->unitsOf[$nid], fn(NodeInterface $u) => !$this->isRetired($u));
  }

  /**
   * TRUE when the tool has at least one unit (retired or not).
   */
  public function hasUnits(NodeInterface $node): bool {
    return $this->getUnits($node, TRUE) !== [];
  }

  /**
   * Label of a node's status term, or '' when unset.
   */
  public function statusLabel(NodeInterface $node): string {
    if (!$node->hasField('field_item_status') || $node->get('field_item_status')->isEmpty()) {
      return '';
    }
    $term = $node->get('field_item_status')->entity;
    return $term ? (string) $term->label() : '';
  }

  /**
   * TRUE when the unit has been retired (Gone).
   */
  public function isRetired(NodeInterface $node): bool {
    return in_array($this->statusLabel($node), self::RETIRED_STATUSES, TRUE);
  }

  /**
   * TRUE when members can use this machine right now.
   */
  public function isUsable(NodeInterface $node): bool {
    if (!$node->hasField('field_item_status') || $node->get('field_item_status')->isEmpty()) {
      return FALSE;
    }
    $term = $node->get('field_item_status')->entity;
    return $term ? $this->availability->isUsable($term) : FALSE;
  }

  /**
   * Roll-up of a tool's units for the tool page and the status board.
   *
   * @return array{total:int, available:int, units:array<int,array{nid:int,title:string,status:string,usable:bool,since:?int,expected_back:?string}>}
   *   Retired units are left out. `since` is the last status_change log time.
   */
  public function summary(NodeInterface $parent): array {
    $units = $this->getUnits($parent);
    $summary = ['total' => count($units), 'available' => 0, 'units' => []];
    if (!$units) {
      return $summary;
    }
    $latest = $this->latestStatusEntries(array_keys($units));
    foreach ($units as $nid => $unit) {
      $usable = $this->isUsable($unit);
      if ($usable) {
        $summary['available']++;
      }
      $summary['units'][$nid] = [
        'nid' => $nid,
        'title' => $unit->label(),
        'status' => $this->statusLabel($unit),
        'usable' => $usable,
        'since' => $latest[$nid]['created'] ?? NULL,
        'expected_back' => $latest[$nid]['expected_back'] ?? NULL,
      ];
    }
    return $summary;
  }

  /**
   * Names a unit after its tool: "Laser 2 (32x18 ULS Laser Cutter)".
   *
   * Slack messages and reminders name the machine this way so a reader who
   * only sees "Laser 2" still knows which tool it belongs to.
   */
  public function displayTitle(NodeInterface $node): string {
    if ($this->isUnit($node) && ($parent = $this->getParent($node))) {
      return sprintf('%s (%s)', $node->label(), $parent->label());
    }
    return (string) $node->label();
  }

  /**
   * Maps unit nids to their parent for a batch of nodes, in one query.
   *
   * @param int[] $nids
   *   Candidate nids (units and non-units alike).
   *
   * @return array<int, int>
   *   unit nid => parent nid, only for nids that are units.
   */
  public function parentMap(array $nids): array {
    $tid = $this->unitTermId();
    if (!$nids || $tid === NULL) {
      return [];
    }
    $query = $this->database->select('node__field_item_set', 's');
    $query->join('node__field_item_category', 'c', 'c.entity_id = s.field_item_set_target_id AND c.deleted = 0');
    $query->fields('s', ['field_item_set_target_id', 'entity_id']);
    $query->condition('s.field_item_set_target_id', array_map('intval', $nids), 'IN');
    $query->condition('s.deleted', 0);
    $query->condition('c.field_item_category_target_id', $tid);
    $map = [];
    foreach ($query->execute() as $row) {
      $map[(int) $row->field_item_set_target_id] = (int) $row->entity_id;
    }
    return $map;
  }

  /**
   * Keeps a tool's own status honest once it has units.
   *
   * The parent's status is not what members see (the block shows the units),
   * but other code still reads it. When every unit is retired the tool is
   * retired; when a retired tool gains a live unit it comes back. Otherwise
   * the parent is left alone, so a deliberate parent-level "Offline" (whole
   * station down) still works.
   *
   * @return bool
   *   TRUE when the parent was changed and saved.
   */
  public function syncParentStatus(NodeInterface $unit): bool {
    if (!$this->isUnit($unit) || !($parent = $this->getParent($unit))) {
      return FALSE;
    }
    unset($this->unitsOf[(int) $parent->id()]);
    $all = $this->getUnits($parent, TRUE);
    if (!$all) {
      return FALSE;
    }
    $live = $this->getUnits($parent);

    if (!$live && !$this->isRetired($parent)) {
      $gone = $this->availability->getTermByLabel('Gone');
      if (!$gone) {
        return FALSE;
      }
      $parent->set('field_item_status', $gone->id());
      $parent->_asset_status_log_message = sprintf('All %d units retired; the last was %s.', count($all), $unit->label());
      $parent->save();
      return TRUE;
    }
    if ($live && $this->isRetired($parent)) {
      $operational = $this->availability->getTermByLabel('Operational');
      if (!$operational) {
        return FALSE;
      }
      $parent->set('field_item_status', $operational->id());
      $parent->_asset_status_log_message = sprintf('Unit %s is back in the fleet.', $unit->label());
      $parent->save();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Latest status_change log entry per unit: created time + expected back.
   *
   * @param int[] $nids
   *   Unit nids.
   *
   * @return array<int, array{created:int, expected_back:?string}>
   *   Keyed by unit nid.
   */
  private function latestStatusEntries(array $nids): array {
    if (!$nids) {
      return [];
    }
    $query = $this->database->select('asset_log_entry', 'l');
    $query->fields('l', ['asset', 'created', 'expected_back']);
    $query->condition('l.asset', $nids, 'IN');
    $query->condition('l.type', 'status_change');
    $query->orderBy('l.created', 'DESC');
    $out = [];
    foreach ($query->execute() as $row) {
      $asset = (int) $row->asset;
      if (!isset($out[$asset])) {
        $out[$asset] = ['created' => (int) $row->created, 'expected_back' => $row->expected_back ?: NULL];
      }
    }
    return $out;
  }

}
