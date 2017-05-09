<?php
/**
 * @file
 * Contains \Drupal\powertagging\Plugin\Field\FieldWidget\PowerTaggingTagsWidget
 */

namespace Drupal\powertagging\Plugin\Field\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\field\Entity\FieldConfig;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\Plugin\Field\FieldType\PowerTaggingTagsItem;
use Drupal\taxonomy\Entity\Term;

/**
 * Plugin implementation of the 'powertagging_tags_default' widget.
 *
 * @FieldWidget(
 *   id = "powertagging_tags_default",
 *   label = @Translation("Term extraction"),
 *   field_types = {
 *     "powertagging_tags"
 *   },
 *   multiple_values = TRUE
 * )
 */
class PowerTaggingTagsWidget extends WidgetBase{

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    $langcode = isset($storage['langcode']) ? $storage['langcode'] : '';

    // Show the legend.
    $legend_types = [
      'concept' => t('Concepts from the thesaurus'),
      'freeterm' => t('Free terms'),
      'disabled' => t('Already selected tags'),
    ];
    $legend = '<div class="powertagging-legend">';
    foreach ($legend_types as $type => $label) {
      $legend .= '<div class="powertagging-legend-item"><span id="powertagging-legend-item-colorbox-' . $type . '" class="powertagging-legend-item-colorbox">&nbsp;</span>' . $label . '</div>';
    }
    $legend .= '</div>';
    $element['legend'] = [
      '#type' => 'item',
      '#markup' => $legend,
    ];

    // Get the selected tag IDs.
    $tag_ids = [];
    foreach ($items as $item) {
      if ($item->target_id !== NULL) {
        $tag_ids[] = $item->target_id;
      }
    }
    $tag_string = implode(',', $tag_ids);

    // Get the default tags if required.
    $field_settings = $this->getFieldSettings();
    $default_terms = [];
    if (empty($tag_ids) && !empty($field_settings['default_tags_field'])) {
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $tagfield_items */
      $tagfield_items = $items->getEntity()->{$field_settings['default_tags_field']};
      if ($tagfield_items->count()) {
        $default_tag_ids = [];
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $item */
        foreach ($tagfield_items as $item) {
          if (($target_id = $item->getValue()['target_id']) !== NULL) {
            $default_tag_ids[] = $target_id;
          }
        }
        $terms = Term::loadMultiple($default_tag_ids);
        /** @var Term $term */
        foreach ($terms as $term) {
          $default_terms[] = $term->getName();
        }
        $tag_string = implode('|,', $default_terms) . '|';
      }
    }

    // Create hidden list for the selected tags.
    $element['tag_string'] = array(
      '#type' => 'hidden',
      '#maxlength' => 32000,
      '#default_value' => !empty($tag_string) ? $tag_string : NULL,
      '#element_validate' => array(array($this, 'validateTags')),
      '#attributes' => array(
        'class' => array('powertagging_tag_string'),
      ),
    );

    // Show the form field.
    $element['powertagging'] = [
      '#type' => 'fieldset',
      '#title' => $element['#title'],
    ];

    // Add a field to display an error if the selected language is not
    // supported.
    $error_markup = t('Tagging is not possible for the currently selected language.');
    if (\Drupal::currentUser()->hasPermission('administer powertagging')) {
      $link = Link::createFromRoute(t('PowerTagging configuration'), 'entity.powertagging.edit_config_form', ['powertagging' => $field_settings['powertagging_id']]);
      $error_markup .= '<br />' . t('Select a PoolParty language in your @powertagging_config.', array('@powertagging_config' => $link->toString()));
    }
    $element['powertagging']['language_error'] = array(
      '#type' => 'item',
      '#markup' => '<div class="messages messages--warning">' . $error_markup . '</div>',
    );

    $element['powertagging']['manual'] = [
      '#type' => 'textfield',
      '#title' => t('Add tags manually'),
      '#description' => t('The autocomplete mechanism will suggest concepts from the thesaurus.'),
      '#attributes' => [
        'class' => ['powertagging_autocomplete_tags', 'form-autocomplete'],
      ],
    ];

    $element['powertagging']['tags_result'] = [
      '#type' => 'item',
      '#title' => t('Your selected tags'),
      '#markup' => '<div class="powertagging-tag-result"><div class="no-tags">' . t('No tags selected') . '</div></div>',
    ];

    $element['powertagging']['tags'] = [
      '#type' => 'item',
      '#title' => t('Tags extracted from'),
      '#markup' => '<div class="ajax-progress-throbber"><div class="throbber">' . t('Loading...') . '</div></div><div class="powertagging-extracted-tags"></div>',
    ];

    $element['powertagging']['get_tags'] = [
      '#value' => t('Get tags'),
      '#type' => 'button',
    ];

    // Attach the libraries.
    $element['#attached'] = [
      'library' => [
        'powertagging/widget',
      ],
      'drupalSettings' => [
        'powertagging' => $this->getJavaScriptSettings($tag_ids, $default_terms, $langcode),
      ]
    ];

    return $element;
  }

  /**
   * Converts the comma separated list from the tag_string into the expected
   * array for the multiple target_id.
   *
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    if (empty($values['tag_string'])) {
      return NULL;
    }

    return explode(',', $values['tag_string']);
  }

  /**
   * Validation handler for the PowerTagging Tags field.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   generic input element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateTags(array $element, FormStateInterface &$form_state) {
    $error = FALSE;

    // Only check if value is not empty.
    if (empty($element['#value'])) {
      return;
    }

    $tags = explode(',', $element['#value']);
    $tids = array();
    $new_tags = array();

    // Check if all tids are integer-values or new freeterms.
    foreach ($tags as $tag) {
      if (is_numeric($tag) && (intval($tag) == floatval($tag))) {
        $tids[] = $tag;
      }
      elseif (is_string($tag)) {
        if (strpos($tag, '|')) {
          $new_tags[] = $tag;
        }
      }
      else {
        $error = TRUE;
      }
    }

    $storage_settings = $this->fieldDefinition->getFieldStorageDefinition()->getSettings();
    $powertagging_id = $storage_settings['powertagging_id'];
    /** @var PowerTaggingConfig $powertagging_config */
    $powertagging_config = PowerTaggingConfig::load($powertagging_id);
    $config = $powertagging_config->getConfig();

    // Get language.
    $langcode = $form_state->getValue('langcode');
    $langcode = $langcode[0]['value'];

    // Check if all the terms are still existent if there was no error till now.
    if (!$error && count($tids)) {
      $terms = Term::loadMultiple($tids);
      // All of the terms are existent.
      if (count($terms) != count($tids)) {
        $error = TRUE;
      }
      // Update data of existing terms if required.
      else {
        $existing_terms_by_uri = array();
        /** @var Term $existing_term */
        foreach ($terms as $existing_term) {
          if (!empty($existing_term->get('field_uri')->getString())) {
            $existing_terms_by_uri[$existing_term->get('field_uri')->getString()] = $existing_term;
          }
        }

        if (!empty($existing_terms_by_uri)) {
          $concepts_details = $this->getConceptsDetails($powertagging_config, array_keys($existing_terms_by_uri), $langcode);
          foreach ($concepts_details as $concept_detail) {
            if (isset($existing_terms_by_uri[$concept_detail['uri']])) {
              $existing_term = $existing_terms_by_uri[$concept_detail['uri']];
              $term_data_changed = $this->setTaxonomyTermDetails($existing_term, (object) $concept_detail);
              // Only save the taxonomy term if any information has changed.
              if ($term_data_changed) {
                $existing_term->save();
              }
            }
          }
        }
      }
    }

    // If there is no error at all, add taxonomy terms for the new freeterms.
    if (!$error && count($new_tags)) {
      $vid = $config['project']['taxonomy_id'];
      $new_tids = $this->addNewTags($powertagging_config, $vid, $new_tags, $langcode);
      $form_state->setValue($element['#parents'], implode(',', array_merge($tids, $new_tids)));
    }

    if ($error) {
      $form_state->setErrorByName($element['#name'], t('Invalid tag selection.'));
    }
  }

  /**
   * Returns the PowerTagging settings for the drupalSettings.
   *
   * @param array $tag_ids
   *   The list of connected tag IDs.
   * @param array $default_terms
   *   The list of default terms.
   * @param string $langcode
   *   The language of the entity.
   * @return array The javascript settings.
   *   The Drupal settings.
   */
  protected function getJavaScriptSettings(array $tag_ids, array $default_terms, $langcode) {
    $field = $this->fieldDefinition;
    $field_settings = $this->getFieldSettings();
    $powertagging_config = PowerTaggingConfig::load($field_settings['powertagging_id'])->getConfig();
    $limits = empty($field->getSetting('limits')) ? $powertagging_config['limits'] : $field->getSetting('limits');

    // Set the existing concepts and free terms.
    $selected_tags = [];
    if (!empty($tag_ids)) {
      $terms = Term::loadMultiple($tag_ids);
      /** @var Term $term */
      foreach ($terms as $term) {
        $selected_tags[] = [
          'tid' => $term->id(),
          'uri' => $term->get('field_uri')->getString(),
          'label' => $term->getName(),
          'type' => empty($term->get('field_uri')->getString()) ? 'freeterm' : 'concept',
        ];
      }
    }

    // Set the default term if available.
    if (!empty($default_terms)) {
      foreach ($default_terms as $term) {
        $selected_tags[] = [
          'tid' => 0,
          'uri' => '',
          'label' => $term,
          'type' => 'freeterm',
        ];
      }
    }

    // Sort the selected tags: concepts on top and free terms to the bottom.
    usort($selected_tags, array($this, 'sortSelectedTags'));

    // Get the configured project languages.
    $allowed_langcodes = [];
    foreach ($powertagging_config['project']['languages'] as $drupal_lang => $pp_lang) {
      if (!empty($pp_lang)) {
        $allowed_langcodes[] = $drupal_lang;
      }
    }

    $settings = [];
    $settings[$field->getName()] = [
      'fields' => $this->getSelectedTaggingFields($field),
      'settings' => [
        'field_name' => $field->getName(),
        'powertagging_id' => $field_settings['powertagging_id'],
        'taxonomy_id' => $powertagging_config['project']['taxonomy_id'],
        'concepts_per_extraction' => $limits['concepts_per_extraction'],
        'concepts_threshold' => $limits['concepts_threshold'],
        'freeterms_per_extraction' => $limits['freeterms_per_extraction'],
        'freeterms_threshold' => $limits['freeterms_threshold'],
        'entity_language' => $langcode,
        'allowed_languages' => $allowed_langcodes,
      ],
      'selected_tags' => $selected_tags,
    ];

    return $settings;
  }

  /**
   * Get the fields that are supported for the tagging.
   *
   * @param FieldDefinitionInterface $field
   *   The field config object.
   *
   * @return array
   *   A list of supported fields.
   */
  protected function getSelectedTaggingFields(FieldDefinitionInterface $field) {
    /** @var \Drupal\Core\Entity\EntityFieldManager $entityFieldManager */
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $field_definitions = $entityFieldManager->getFieldDefinitions($field->getTargetEntityTypeId(), $field->getTargetBundle());
    $supported_field_types = PowerTaggingTagsItem::getSupportedFieldTypes();
    $supported_fields = [];

    $field_settings = $this->getFieldSettings();
    $selected_fields = $field_settings['fields'];

    switch ($field->getTargetEntityTypeId()) {
      case 'node':
        if (isset($selected_fields['title']) && $selected_fields['title']) {
          $supported_fields[] = [
            'field_name' => 'title',
            'module' => 'core',
            'widget' => 'string_textfield',
          ];
        }
        break;

      case 'taxonomy_term':
        if (isset($selected_fields['name']) && $selected_fields['name']) {
          $supported_fields[] = [
            'field_name' => 'name',
            'module' => 'core',
            'widget' => 'string_textfield',
          ];
        }
        if (isset($selected_fields['description']) && $selected_fields['description']) {
          $supported_fields[] = [
            'field_name' => 'description',
            'module' => 'text',
            'widget' => 'text_textarea',
          ];
        }
        break;
    }

    foreach ($field_definitions as $field_definition) {
      if (!$field_definition instanceof FieldConfig) {
        continue;
      }
      $field_storage = $field_definition->getFieldStorageDefinition();
      $field_name = $field_definition->getName();
      if (isset($supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()]) &&
        isset($selected_fields[$field_name]) && $selected_fields[$field_name]) {
        $supported_fields[] = [
          'field_name' => $field_name,
          'module' => $field_storage->getTypeProvider(),
          'widget' => $supported_field_types[$field_storage->getTypeProvider()][$field_storage->getType()],
        ];
      }
    }

    return $supported_fields;
  }

  /**
   * Add new concepts or freeterms to the vocabulary of a PoolParty project.
   *
   * @param PowerTaggingConfig $powertagging_config
   *   The current PowerTagging configuration.
   * @param string $vid
   *   The vocabulary ID in which the new tag must be stored.
   * @param array $new_tags
   *   Array of strings of new tag to add.
   * @param string $langcode
   *   The Drupal language of the terms to add.
   *
   * @return array
   *   Array of created term-ids.
   */
  protected function addNewTags(PowerTaggingConfig $powertagging_config, $vid, array $new_tags, $langcode) {
    $tag_ids = array();
    $parent = $this->getTermListIds($vid, $langcode);

    // Collect all the URIs and get the concept details of it.
    $new_uris = [];
    foreach ($new_tags as $new_tag) {
      list(, $uri) = explode('|', $new_tag);
      if (!empty($uri)) {
        $new_uris[] = $uri;
      }
    }
    $concepts_details = $this->getConceptsDetails($powertagging_config, $new_uris, $langcode);
    $concepts_details_by_uri = array();
    foreach ($concepts_details as $concepts_detail) {
      $concepts_details_by_uri[$concepts_detail['uri']] = (object) $concepts_detail;
    }
    $concepts_details = $concepts_details_by_uri;
    unset($concepts_details_by_uri);

    // Go through all new tags.
    foreach ($new_tags as $new_tag) {
      list($label, $uri) = explode('|', $new_tag);

      // Check if the term already exists.
      $old_term = \Drupal::entityQuery('taxonomy_term')
        ->condition('name', $label)
        ->condition('vid', $vid)
        ->condition('langcode', $langcode)
        ->execute();

      // If the term already exists and the entered term has no URI then do
      // nothing --> This case should never appear.
      if (!empty($old_term) && empty($uri)) {
        $tag_ids[] = array_shift($old_term);
        continue;
      }

      // Load the term if exists.
      if (!empty($old_term)) {
        $term = Term::load(array_shift($old_term));
      }
      // Otherwise instantiate a new term.
      else {
        $term = Term::create([
          'name' => $label,
          'vid' => $vid,
          'langcode' => $langcode,
        ]);
      }

      // Set the detail information if URI exists
      if (!empty($uri)) {
        $this->setTaxonomyTermDetails($term, $concepts_details[$uri]);
      }

      // Save the taxonomy term.
      $term->save();

      // Put the term into the "Concepts" or "Free terms" list.
      // Delete old hierarchy values.
      \Drupal::database()->delete('taxonomy_term_hierarchy')
        ->condition('tid', $term->id())
        ->execute();

      // Insert new hierarchy values.
      $parent_id = !empty($uri) ? $parent['concepts'] : $parent['freeterms'];
      \Drupal::database()->insert('taxonomy_term_hierarchy')
        ->fields(array('tid', 'parent'))
        ->values(['tid' => $term->id(), 'parent' => $parent_id])
        ->execute();

      $tag_ids[] = $term->id();
    }

    return $tag_ids;
  }

  /**
   * Get the list of IDs of the top terms from a vocabulary.
   *
   * @param string $vid
   *   The ID of a vocabulary.
   * @param string $langcode
   *   The language.
   *
   * @return array
   *   The list of the top terms.
   */
  protected function getTermListIds($vid, $langcode) {
    $list_ids = array(
      'concepts' => NULL,
      'freeterms' => NULL,
    );
    $list_names = array(
      'Concepts' => 'concepts',
      'Free Terms' => 'freeterms',
    );

    // Get the top terms of the vocabulary.
    /** @var \Drupal\taxonomy\TermStorage $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tree = $storage->loadTree($vid, 0);
    $list_terms = [];
    if (!empty($tree)) {
      foreach ($tree as $term) {
        $list_terms[$term->name] = $term->tid;
      }
    }

    // Go through the list terms.
    foreach ($list_names as $list_name => $list_key) {
      // Check if "Concepts" and "Free Terms" exists as top terms.
      if (isset($list_terms[$list_name])) {
        $list_ids[$list_key] = $list_terms[$list_name];
      }
      // If not then create it.
      else {
        $term = Term::create([
          'name' => $list_name,
          'vid' => $vid,
          'langcode' => $langcode,
        ]);
        $term->save();
        $list_ids[$list_key] = $term->id();
      }
    }

    return $list_ids;
  }

  /**
   * Get detail information for a list of concept URIs.
   *
   * @param PowerTaggingConfig $powertagging_config
   *   The current PowerTagging configuration.
   * @param array $uris
   *   An Array or URIs of the concepts.
   * @param string $langcode
   *   The language of the concepts.
   *
   * @return array An array of concept detail information.
   *   An array of concept detail information.
   */
  protected function getConceptsDetails(PowerTaggingConfig $powertagging_config, array $uris, $langcode='') {
    $concepts =  $powertagging_config->getConnection()->getApi('PPT')->getConcepts($powertagging_config->getProjectId(), $uris, [
      'skos:prefLabel',
      'skos:altLabel',
      'skos:hiddenLabel',
      'skos:definition',
      'skos:exactMatch',
    ], $langcode);

    return $concepts;
  }

  /**
   * Update a taxonomy term with data received from the PPT API.
   *
   * @param Term $term
   *   The object of the taxonomy term, which will receive the new detail data.
   * @param object $concept_details
   *   An array of concept detail data to update the term with.
   *
   * @return bool
   *   TRUE if data has changed, FALSE if everything was up to date already.
   */
  protected function setTaxonomyTermDetails(Term &$term, $concept_details) {
    $data_has_changed = FALSE;

    // Set the taxonomy name.
    if (isset($concept_details->prefLabel)) {
      if ($term->getName() != $concept_details->prefLabel) {
        $data_has_changed = TRUE;
        $term->setName($concept_details->prefLabel);
      }
    }

    // Set the URI.
    if (isset($concept_details->uri)) {
      if ($term->get('field_uri')->getString() != $concept_details->uri) {
        $data_has_changed = TRUE;
        $term->get('field_uri')->setValue($concept_details->uri);
      }
    }

    // Set the description.
    if (isset($concept_details->definitions) && !empty($concept_details->definitions)) {
      $description = '<p>' . implode('</p><p>', $concept_details->definitions) . '</p>';
      if ($term->getDescription() != $description) {
        $data_has_changed = TRUE;
        $term->setDescription($description);
      }
    }

    // Set alternative labels.
    if (isset($concept_details->altLabels)) {
      $alt_labels = implode(',', $concept_details->altLabels);
      if ($term->get('field_alt_labels')->getString() != $alt_labels) {
        $data_has_changed = TRUE;
        $term->get('field_alt_labels')->setValue($alt_labels);
      }
    }

    // Set hidden labels.
    if (isset($concept_details->hiddenLabels)) {
      $hidden_labels = implode(',', $concept_details->hiddenLabels);
      if ($term->get('field_hidden_labels')->getString() != $hidden_labels) {
        $data_has_changed = TRUE;
        $term->get('field_hidden_labels')->setValue($hidden_labels);
      }
    }

    // Set exact match values.
    if (isset($concept_details->exactMatch) && !empty($concept_details->exactMatch)) {
      $concept_count = count($concept_details->exactMatch);
      $term_count = $term->get('field_exact_match')->count();

      if ($concept_count != $term_count) {
        $term->get('field_exact_match')->setValue(NULL);
      }
      for ($i = 0; $i < $concept_count; $i++) {
        if (!$term->get('field_exact_match')->get($i) || $term->get('field_exact_match')->get($i)->getString() != $concept_details->exactMatch[$i]) {
          $data_has_changed = TRUE;
          $term->get('field_exact_match')->set($i, $concept_details->exactMatch[$i]);
        }
      }
    }

    return $data_has_changed;
  }


  /**
   * Callback function to sort the selected tags.
   *
   * Sort the selected tags: concepts on top and free terms to the bottom.
   */
  protected function sortSelectedTags($a, $b) {
    if ($a['type'] == $b['type']) {
      return strcasecmp($a['label'], $b['label']);
    }

    return ($a['type'] == 'freeterm') ? 1 : -1;
  }

}