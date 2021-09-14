<?php

namespace Drupal\metastore\Reference;

use Contracts\FactoryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\metastore\Factory\MetastoreItemFactoryInterface;

/**
 * Service to find metastore items referencing an identifier.
 */
class ReferenceLookup {

  /**
   * Metastore storage service.
   *
   * @var \Contracts\FactoryInterface
   */
  private $metastoreStorage;

  /**
   * Metastore item factory - for loading full metastore objects.
   *
   * @var \Drupal\metastore\Factory\MetastoreItemFactoryInterface
   */
  private $metastoreItemFactory;

  /**
   * Cache tag invalidator service.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  private $invalidator;

  /**
   * Constructor.
   */
  public function __construct(
    FactoryInterface $metastoreStorage,
    MetastoreItemFactoryInterface $metastoreItemFactory,
    CacheTagsInvalidatorInterface $invalidator
  ) {
    $this->metastoreStorage = $metastoreStorage;
    $this->metastoreItemFactory = $metastoreItemFactory;
    $this->invalidator = $invalidator;
  }

  /**
   * Get UUIDs of all metastore items referencing an ID through a property.
   *
   * @param string $schemaId
   *   The type of metadata to look for references within.
   * @param string $referenceId
   *   The UUID of the reference we're looking for.
   * @param string $propertyId
   *   The metadata property we hope to find it in.
   *
   * @return array
   *   Array of metastore UUIDs for matching items.
   *
   * @todo Refactor when this storage vs item factory mess is resolved.
   */
  public function getReferencers(string $schemaId, string $referenceId, string $propertyId) {

    // This will give us a smaller subset of metastore items to parse through.
    $metastoreItems = $this->metastoreStorage->getInstance($schemaId)->retrieveContains($referenceId);

    $referencers = [];

    foreach ($metastoreItems as $item) {
      $metadata = json_decode($item);
      $item = $this->metastoreItemFactory->getInstance($metadata->identifier);
      $raw = $item->getRawMetadata();
      $value = $raw->{$propertyId};
      // Check if uuid is found either directly or in an array.
      $idIsValue = $referenceId == $value;
      $idInArray = is_array($value) && in_array($referenceId, $value);
      if ($idIsValue || $idInArray) {
        $referencers[] = $metadata->identifier;
      }
    }

    return $referencers;
  }

  /**
   * Invalidate cache tags in any items pointing to a reference.
   *
   * @param string $schemaId
   *   The type of metadata to look for references within.
   * @param string $referenceId
   *   The UUID of the reference we're looking for.
   * @param string $propertyId
   *   The metadata property we hope to find it in.
   */
  public function invalidateReferencerCacheTags(string $schemaId, string $referenceId, string $propertyId) {
    $referencers = $this->getReferencers($schemaId, $referenceId, $propertyId);
    $tags = [];
    foreach ($referencers as $identifier) {
      $item = $this->metastoreItemFactory->getInstance($identifier);
      $tags[] = Cache::mergeTags($tags, $item->getCacheTags());
    }
    $this->invalidator->invalidateTags($tags);
  }

}