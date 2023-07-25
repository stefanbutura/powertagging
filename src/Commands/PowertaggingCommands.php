<?php

namespace Drupal\powertagging\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\PowerTagging;

class PowertaggingCommands {

  use StringTranslationTrait;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The request stack.
   *
   * @var \Drupal\Core\Http\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PowertaggingCommands object.
   *
   * @param LoggerChannelFactoryInterface $logger
   *   The logger.
   * @param \Drupal\Core\Http\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(LoggerChannelFactoryInterface $logger, RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager) {
    $this->logger = $logger->get('powertagging');
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * A custom Drush command to displays the given text.
   *
   * @command powertagging:tag-content
   *
   * @param string $powertagging_config
   *   The powertagging config ID.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $field
   *   The field ID.
   * @param array $options
   *   Array of options as described below.
   *
   * @option keep-existing-tags Keep the existing tags.
   * @option skip-tagged-content Skip tagged content.
   */
  public function tagContent(string $powertagging_config, string $entity_type_id, string $bundle, string $field, array $options = ['keep-existing-tags' => FALSE, 'skip-tagged-content' => FALSE]) {
    $config = PowerTaggingConfig::load($powertagging_config);
    if (empty($config)) {
      $this->messenger->addError($this->t('Unknown powertagging config @config', [
        '@config' => $powertagging_config,
      ]));
    }

    $powertagging = new PowerTagging($config);

    $field_info = [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
      'field_type' => $field,
    ];

    $tag_settings = $powertagging->buildTagSettings($field_info, [
      'skip_tagged_content' => $options['skip-tagged-content'] ?? FALSE,
      'keep_existing_tags' => $options['keep-existing-tags'] ?? FALSE,
    ]);

    $bundle_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle');
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->condition($bundle_key, $bundle);

    if (!empty($options['skip-tagged-content'])) {
      $query->notExists($field);
    }

    $entities = $query->execute();

    if (empty($entities)) {
      $this->logger->notice($this->t('No entities to tag'));
      return;
    }

    $this->logger->notice($this->t('Started tagging @count entities', [
      '@count' => count($entities),
    ]));

    $idx = 0;

    foreach ($entities as $entity) {
      $idx++;
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity);

      if (!$entity) {
        continue;
      }

      $existing_tags = [];
      if (!empty($options['keep-existing-tags']) && $entity->hasField($field)) {
        $existing_tags = $entity->get($field)->getValue();
      }

      try {
        $tags = $powertagging->extractTagsOfEntity($entity, $tag_settings);
        $tag_ids = array_column($tags, 'target_id');
        foreach ($existing_tags as $existing_tag) {
          if (in_array($existing_tag['target_id'], $tag_ids)) {
            continue;
          }
          $tags[] = $existing_tag;
        }

        $entity->set($field, $tags);
        $entity->save();

        $this->logger->notice($this->t('Successfully tagged @entity_type @entity_id (@index/@count)', [
          '@entity_type' => $entity_type_id,
          '@entity_id' => $entity->id(),
          '@index' => $idx,
          '@count' => count($entities),
        ]));
      }
      catch (\Exception $e) {
        $this->logger->error($e->getMessage());
      }
    }

    $this->logger->notice($this->t('Successfully tagged @count entities.', [
      '@count' => $idx,
    ]));
  }

}
