<?php

namespace Drupal\harvest;

use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Controller.
 */
class DashboardController {

  use StringTranslationTrait;

  const HARVEST_HEADERS = [
    'Harvest ID',
    'Extract Status',
    'Last Run',
    '# of Datasets',
  ];

  /**
   * Harvest service.
   *
   * @var \Drupal\harvest\Service
   */
  protected $harvest;

  /**
   * Controller constructor.
   */
  public function __construct() {
    $this->harvest = \Drupal::service('dkan.harvest.service');
  }

  /**
   * A list of harvests and some status info.
   */
  public function harvests(): array {

    date_default_timezone_set('EST');

    $rows = [];
    foreach ($this->harvest->getAllHarvestIds() as $harvestId) {
      // @todo Make Harvest Service's private getLastHarvestRunId() public,
      //   And replace 7-8 cases where we recreate it.
      $runIds = $this->harvest->getAllHarvestRunInfo($harvestId);

      if ($runId = end($runIds)) {
        $info = json_decode($this->harvest->getHarvestRunInfo($harvestId, $runId));

        $rows[] = $this->buildHarvestRow($harvestId, $runId, $info);
      }
    }

    return [
      '#theme' => 'table',
      '#header' => self::HARVEST_HEADERS,
      '#rows' => $rows,
      '#attributes' => ['class' => 'dashboard-harvests'],
      '#attached' => ['library' => ['harvest/style']],
      '#empty' => "No harvests found",
    ];
  }

  /**
   * Private.
   */
  private function buildHarvestRow(string $harvestId, string $runId, $info) {
    $queryParams = [
      'harvest_id' => $harvestId,
    ];
    $url = Url::fromUri('base:provider-data/dashboard/datastore', ['query' => $queryParams]);

    return [
      'harvest_link' => Link::fromTextAndUrl($harvestId, $url),
      'extract_status' => [
        'data' => $info->status->extract,
        'class' => strtolower($info->status->extract),
      ],
      'last_run' => date('m/d/y H:m:s T', $runId),
      'dataset_count' => count(array_keys((array) $info->status->load)),
    ];
  }

}
