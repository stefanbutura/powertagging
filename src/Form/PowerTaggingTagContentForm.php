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
      $form['powertagging_config'] = array(
        '#type' => 'value',
        '#value' => $powertagging_config,
      );

      $form['content_types'] = array(
        '#title' => t('Entity types to be included in the batch process'),
        '#type' => 'checkboxes',
        '#options' => $powertagging_config->renderFields('option_list', $fields),
        '#required' => TRUE,
      );

      $form['skip_tagged_content'] = array(
        '#title' => t('Skip already tagged content'),
        '#type' => 'radios',
        '#options' => array(
          '1' => t('Yes'),
          '0' => t('No'),
        ),
        '#default_value' => TRUE,
      );

      $form['entities_per_request'] = array(
        '#type' => 'number',
        '#title' => t('Entities per request'),
        '#description' => t('The number of entities, that get processed during one HTTP request. (Allowed value range: 1 - 100)') . '<br />' . t('The higher this number is, the less HTTP requests have to be sent to the server until the batch finished tagging ALL your entities, what results in a shorter duration of the bulk tagging process.') . '<br />' . t('Numbers too high can result in a timeout, which will break the whole bulk tagging process.') . '<br />' . t('If entities are configured to get tagged with uploaded files, a value of 5 or below is recommended.'),
        '#required' => TRUE,
        '#default_value' => '10',
        '#min' => 1,
        '#max' => 100,
      );

      $form['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Start process'),
      );
    }
    else {
      $form['error'] = array(
        '#markup' => '<div class="messages messages--error">' . t('No taggable content types found for PowerTagging configuration "%ptconfname".', array('%ptconfname' => $powertagging_config->getTitle())) . '</div>',
      );
    }

    $form['cancel'] = array(
      '#type' => 'link',
      '#title' => t('Cancel'),
      '#url' => Url::fromRoute('entity.powertagging.edit_config_form', ['powertagging' => $powertagging_config->id()]),
      '#attributes' => [
        'class' => ['button'],
      ],
    );

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
    $batch = array(
      'title' => t('Tagging entities'),
      'operations' => array(),
      'init_message' => t('Start with the tagging of the entities.'),
      'progress_message' =>  t('Process @current out of @total.'),
      'finished' => array('\Drupal\powertagging\PowerTagging','tagContentBatchFinished'),
    );

    foreach ($content_types as $content_type) {
      list($entity_type_id, $bundle, $field_type) = explode('|', $content_type);

      // If the entity type is not supported, throw an error and continue.
      if (!in_array($entity_type_id, array('node', 'user', 'taxonomy_term'))) {
        drupal_set_message(t('Entity type "%entitytype" is not supported in bulk tagging.', array('%entitytype' => $entity_type_id)), 'error');
        continue;
      }

      $settings = $powertagging_config->getFieldSettings(['entity_type_id' => $entity_type_id, 'bundle' => $bundle, 'field_type' => $field_type]);
      $tag_fields = array();
      foreach ($settings['fields'] as $tag_field_type) {
        if ($tag_field_type && !isset($tag_fields[$tag_field_type])) {
          $info  = $this->getInfoForTaggingField(['entity_type_id' => $entity_type_id, 'bundle' => $bundle, 'field_type' => $tag_field_type]);
          if (!empty($info)) {
            $tag_fields[$tag_field_type] = $this->getInfoForTaggingField($entity_type_id, $bundle, $tag_field_type);
          }
        }
      }
      var_dump($tag_fields);
      exit();

      $tag_settings = array(
        'powertagging_id' => $powertagging_config->powertagging_id,
        'powertagging_config' => $powertagging_config,
        'taxonomy_id' => $powertagging_config->config['projects'][$powertagging_config->project_id]['taxonomy_id'],
        'concepts_per_extraction' => $instance['settings']['concepts_per_extraction']['value'],
        'concepts_threshold' => $instance['settings']['concepts_threshold']['value'],
        'freeterms_per_extraction' => $instance['settings']['freeterms_per_extraction']['value'],
        'freeterms_threshold' => $instance['settings']['freeterms_threshold']['value'],
        'fields' => $tag_fields,
        'skip_tagged_content' => $form_state->getValue('skip_tagged_content'),
        'default_tags_field' => (isset($instance['settings']['default_tags_field']) ? $instance['settings']['default_tags_field'] : ''),
      );

      // Get all entities for the given content type.
      switch ($entity_type_id) {
        case 'node':
          $result = db_select('node', 'n')
            ->fields('n', array('nid'))
            ->condition('n.type', $bundle)
            ->execute();
          $count = $result->rowCount();
          $entity_ids = $result->fetchCol();
          break;

        case 'user':
          $result = db_select('users', 'u')
            ->fields('u', array('uid'))
            ->condition('u.status', 0, '>')
            ->execute();
          $count = $result->rowCount();
          $entity_ids = $result->fetchCol();
          break;

        case 'taxonomy_term':
          $query = db_select('taxonomy_term_data', 't');
          $query->join('taxonomy_vocabulary', 'v', 't.vid = v.vid');
          $result = $query->fields('t', array('tid'))
            ->condition('v.machine_name', $bundle)
            ->execute();
          $count = $result->rowCount();
          $entity_ids = $result->fetchCol();
          break;
      }

      $total += $count;
      for ($i = 0; $i < $count; $i += $entities_per_request) {
        $entities = array_slice($entity_ids, $i, $entities_per_request);
        $batch['operations'][] = array(
          'powertagging_update_entity_tags',
          array($entities, $entity_type_id, $field_type, $tag_settings),
        );
      }
    }

    // Add for each operation some info data.
    $batch_info = array(
      'total' => $total,
      'start_time' => $start_time,
    );
    foreach ($batch['operations'] as &$operation) {
      $operation[1][] = $batch_info;
    }

    batch_set($batch);
    return TRUE;
  }

  /**
   * Gets the module and widget for a given field.
   *
   * @param string $entity_type_id
   *   The entity type ID of the entity.
   * @param string $bundle
   *   The bundle of the entity.
   * @param string $field_type
   *   The field type of the field.
   *
   * @return array
   *   Module and widget info for a field.
   */
  protected function getInfoForTaggingField($entity_type_id, $bundle, $field_type) {
    if ($entity_type_id == 'node' && $field_type == 'title') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($entity_type_id == 'taxonomy_term' && $field_type == 'name') {
      return [
        'module' => 'core',
        'widget' => 'string_textfield',
      ];
    }

    if ($entity_type_id == 'taxonomy_term' && $field_type == 'description') {
      return [
        'module' => 'text',
        'widget' => 'text_textarea',
      ];
    }

    /** @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager */
    $entityFieldManager = \Drupal::service('entity_field.manager');
    /** @var FieldConfig $field_definition */
    $field_definition = $entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_type];

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