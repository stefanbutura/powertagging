<?php
/**
 * @file
 * Contains \Drupal\powertagging\Form\PowerTaggingTagContentForm.
 */

namespace Drupal\powertagging\Form;


use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem;

class PowerTaggingTagContentForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'poolparty_tag_content_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param PowerTaggingConfig $powertagging_config
   *   An associative array containing the structure of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, PowerTaggingConfig $powertagging_config = NULL) {
    $fields = $powertagging_config->getFields();
    if (!empty($fields)) {
      $form['powertagging_config'] = [
        '#type' => 'value',
        '#value' => $powertagging_config,
      ];

      $form['content_types'] = [
        '#title' => t('Entity types to be included in the batch process'),
        '#type' => 'checkboxes',
        '#options' => $powertagging_config->renderFields('option_list', $fields),
        '#required' => TRUE,
      ];

      $form['skip_tagged_content'] = [
        '#title' => t('Skip already tagged content'),
        '#type' => 'radios',
        '#options' => [
          '1' => t('Yes'),
          '0' => t('No'),
        ],
        '#default_value' => TRUE,
      ];

      $form['entities_per_request'] = [
        '#type' => 'number',
        '#title' => t('Entities per request'),
        '#description' => t('The number of entities, that get processed during one HTTP request. (Allowed value range: 1 - 100)') . '<br />' . t('The higher this number is, the less HTTP requests have to be sent to the server until the batch finished tagging ALL your entities, what results in a shorter duration of the bulk tagging process.') . '<br />' . t('Numbers too high can result in a timeout, which will break the whole bulk tagging process.') . '<br />' . t('If entities are configured to get tagged with uploaded files, a value of 5 or below is recommended.'),
        '#required' => TRUE,
        '#default_value' => '10',
        '#min' => 1,
        '#max' => 100,
      ];

      $form['submit'] = [
        '#type' => 'submit',
        '#value' => t('Start process'),
      ];
    }
    else {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' . t('No taggable content types found for PowerTagging configuration "%ptconfname".', ['%ptconfname' => $powertagging_config->getTitle()]) . '</div>',
      ];
    }

    $form['cancel'] = [
      '#type' => 'link',
      '#title' => t('Cancel'),
      '#url' => Url::fromRoute('entity.powertagging.edit_config_form', ['powertagging' => $powertagging_config->id()]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entities_per_request = $form_state->getValue('entities_per_request');
    if (empty($entities_per_request) || !ctype_digit($entities_per_request) || (int) $entities_per_request == 0 || (int) $entities_per_request > 100) {
      $form_state->setErrorByName('entities_per_request', t('Only values in the range of 1 - 100 are allowed for field "Entities per request"'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = $form_state->getValue('powertagging_config');
    $entities_per_request = $form_state->getValue('entities_per_request');
    $content_types = $form_state->getValue('content_types');
    $start_time = time();
    $total = 0;
    $batch = [
      'title' => t('Tagging entities'),
      'operations' => [],
      'init_message' => t('Start with the tagging of the entities.'),
      'progress_message' => t('Process @current out of @total.'),
      'finished' => [
        '\Drupal\powertagging\PowerTagging',
        'tagContentBatchFinished',
      ],
    ];

    foreach ($content_types as $content_type) {
      if (empty($content_type)) {
        continue;
      }
      list($entity_type_id, $bundle, $field_type) = explode('|', $content_type);

      // If the entity type is not supported, throw an error and continue.
      if (!in_array($entity_type_id, ['node', 'user', 'taxonomy_term'])) {
        drupal_set_message(t('Entity type "%entitytype" is not supported in bulk tagging.', ['%entitytype' => $entity_type_id]), 'error');
        continue;
      }

      $settings = $powertagging_config->getFieldSettings([
        'entity_type_id' => $entity_type_id,
        'bundle' => $bundle,
        'field_type' => $field_type,
      ]);
      $tag_fields = [];
      foreach ($settings['fields'] as $tag_field_type) {
        if ($tag_field_type && !isset($tag_fields[$tag_field_type])) {
          $info = $this->getInfoForTaggingField([
            'entity_type_id' => $entity_type_id,
            'bundle' => $bundle,
            'field_type' => $tag_field_type,
          ]);
          if (!empty($info)) {
            $tag_fields[$tag_field_type] = $info;
          }
        }
      }

      $configuration = $powertagging_config->getConfig();
      $tag_settings = [
        'powertagging_id' => $powertagging_config->id(),
        'powertagging_config' => $powertagging_config,
        'taxonomy_id' => $configuration['project']['taxonomy_id'],
        'concepts_per_extraction' => $settings['limits']['concepts_per_extraction'],
        'concepts_threshold' => $settings['limits']['concepts_threshold'],
        'freeterms_per_extraction' => $settings['limits']['freeterms_per_extraction'],
        'freeterms_threshold' => $settings['limits']['freeterms_threshold'],
        'fields' => $tag_fields,
        'skip_tagged_content' => $form_state->getValue('skip_tagged_content'),
        'default_tags_field' => (isset($settings['default_tags_field']) ? $settings['default_tags_field'] : ''),
      ];

      // Get all entities for the given content type.
      $entity_ids = [];
      switch ($entity_type_id) {
        case 'node':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->condition('type', $bundle)
            ->execute();
          break;

        case 'user':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->execute();
          // Remove the user with the ID = 0.
          array_shift($entity_ids);
          break;

        case 'taxonomy_term':
          $entity_ids = \Drupal::entityQuery($entity_type_id)
            ->execute();
          break;
      }
      $count = count($entity_ids);

      $total += $count;
      for ($i = 0; $i < $count; $i += $entities_per_request) {
        $entities = array_slice($entity_ids, $i, $entities_per_request);
        $batch['operations'][] = [
          [
            '\Drupal\powertagging\PowerTagging',
            'tagContentBatchProcess',
          ],
          [$entities, $entity_type_id, $field_type, $tag_settings],
        ];
        $this->batchtest($entities, $entity_type_id, $field_type, $tag_settings);
      }
    }

    echo 'ende';
    exit();

    // Add for each operation some batch info data.
    $batch_info = [
      'total' => $total,
      'start_time' => $start_time,
    ];
    foreach ($batch['operations'] as &$operation) {
      $operation[1][] = $batch_info;
    }

    batch_set($batch);
    return TRUE;
  }

  private function batchtest($entity_ids, $entity_type_id, $field_type, $tag_settings) {
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
      $context['results']['tagged'] = 0;
      $context['results']['skipped'] = 0;
    }

    // Load the entities.
    $entities = \Drupal::entityTypeManager()
      ->getStorage($entity_type_id)
      ->loadMultiple($entity_ids);

    /** @var \Drupal\Core\Entity\ContentEntityBase $entity */
    // Go through all the entities
    foreach ($entities as $entity) {
      $context['results']['processed']++;

      // Return if this entity does not need to be tagged.
      if ($tag_settings['skip_tagged_content'] && $entity->hasField($field_type) &&
        $entity->get($field_type)->count()
      ) {
        $context['results']['skipped']++;
        continue;
      }

      // Build the content.
      $tag_contents = [];
      $file_ids = [];
      foreach ($tag_settings['fields'] as $tag_field_name => $tag_type) {
        if (!$entity->hasField($tag_field_name) || !$entity->get($tag_field_name)->count()) {
          continue;
        }

        echo "$entity_type_id -> $tag_field_name";
        var_dump($entity->get($tag_field_name)->count());

      }
    }
  }

  /**
   * Gets the module and widget for a given field.
   *
   * @param array $field
   *   The field array with entity type ID, bundle and field type.
   *
   * @return array
   *   Module and widget info for a field.
   */
  protected function getInfoForTaggingField(array $field) {
    if ($field['entity_type_id'] == 'node' && $field['field_type'] == 'title') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($field['entity_type_id'] == 'taxonomy_term' && $field['field_type'] == 'name') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($field['entity_type_id'] == 'taxonomy_term' && $field['field_type'] == 'description') {
      return [
        'module' => 'text',
        'widget' => 'text_textarea',
      ];
    }

    /** @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager */
    $entityFieldManager = \Drupal::service('entity_field.manager');
    /** @var FieldConfig $field_definition */
    $field_definition = $entityFieldManager->getFieldDefinitions($field['entity_type_id'], $field['bundle'])[$field['field_type']];

    if (!$field_definition instanceof FieldConfig) {
      return [];
    }

    $field_storage = $field_definition->getFieldStorageDefinition();
    $supported_field_types = PowerTaggingTagsItem::getSupportedFieldTypes();

    return [
      'module' => $field_storage->getTypeProvider(),
      'widget' => $supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()],
    ];
  }
}