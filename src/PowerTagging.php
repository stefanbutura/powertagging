<?php

/**
 * @file
 * The main class of the PowerTagging module.
 */

namespace Drupal\powertagging;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\semantic_connector\Api\SemanticConnectorPPXApi;
use Drupal\taxonomy\Entity\Term;

/**
 * A collection of static functions offered by the PowerTagging module.
 */
class PowerTagging {

  protected $config;

  protected $config_settings;

  /* @param SemanticConnectorPPXApi $PPXApi */
  protected $PPXApi;

  protected $result;

  /**
   * PowerTagging constructor.
   *
   * @param PowerTaggingConfig $config
   *   The configuration of the PowerTagging.
   */
  public function __construct($config) {
    $this->config = $config;
    $this->config_settings = $config->getConfig();
    $this->PPXApi = $config->getConnection()->getApi('PPX');
    $this->result = NULL;
  }

  /**
   * Getter-function for the result-variable.
   *
   * @return array
   *   Array of results
   */
  public function getResult() {
    return $this->result;
  }

  /**
   * Extracts concepts and free terms from the content and files.
   *
   * @param string $content
   *   The text content to extract tags from.
   * @param array $files
   *   Array of Drupal file IDs of files to extract tags from.
   * @param array $settings
   *   Array of settings to use for the extraction process.
   *
   * @return bool
   *   TRUE if the search was successful, FALSE if not.
   */
  public function extract($content, array $files, array $settings) {
    $project_config = $this->config_settings['project'];
    $corpus_id = !empty($project_config['corpus_id']) ? $project_config['corpus_id'] : '';

    $param = [
      'projectId' => $this->config->getProjectId(),
      'numberOfConcepts' => (int) $settings['concepts_per_extraction']['slider'],
      'numberOfTerms' => (int) $settings['freeterms_per_extraction']['slider'],
      'corpusScoring' => $corpus_id,
    ];

    $tags = [
      'content' => [
        'concepts' => [],
        'freeterms' => [],
      ],
      'suggestion' => [
        'concepts' => [],
        'freeterms' => [],
      ],
      'messages' => [],
    ];
    $suggestion = [
      'concepts' => [],
      'freeterms' => [],
    ];

    // Find out what language to extract.
    $project_languages = $project_config['languages'];

    // Language mapping for the content languages exists.
    if (!empty($project_languages[$settings['entity_language']])) {

      // Extract the concepts and free terms.
      if (!empty($settings['taxonomy_id'])) {
        // Remove line breaks and HTML tags from the content and convert HTML
        // characters to normal ones.
        $content = trim(html_entity_decode(str_replace([
          "\r",
          "\n",
          "\t",
        ], ' ', strip_tags($content)), ENT_COMPAT, 'UTF-8'));

        if (!empty($content)) {
          $extraction = $this->PPXApi->extractConcepts($content, $project_languages[$settings['entity_language']], $param, 'text');
          $extracted_tags = $this->extractTags($extraction, $settings);
          $tags['content'] = $extracted_tags;
          $suggestion['concepts'] = array_merge($suggestion['concepts'], $extracted_tags['concepts']);
          $suggestion['freeterms'] = array_merge($suggestion['freeterms'], $extracted_tags['freeterms']);
        }

        if (!empty($files)) {
          $tags['files'] = [];
          foreach ($files as $file_id) {
            $file = File::load($file_id);
            // Use only existing files for tagging.
            if (file_exists($file->getFileUri())) {
              $extraction = $this->PPXApi->extractConcepts($file, $project_languages[$settings['entity_language']], $param, 'file');
              $extracted_tags = $this->extractTags($extraction, $settings);
              $tags['files'][$file->getFilename()] = $extracted_tags;
              $suggestion['concepts'] = array_merge($suggestion['concepts'], $extracted_tags['concepts']);
              $suggestion['freeterms'] = array_merge($suggestion['freeterms'], $extracted_tags['freeterms']);
            }
          }
        }
      }

      // Merge all extracted concepts and free terms for the suggestion.
      if (!empty($suggestion['concepts'])) {
        usort($suggestion['concepts'], [$this, 'sortByScore']);
        $uris = [];
        $count = 1;
        foreach ($suggestion['concepts'] as $tag) {
          if (in_array($tag['uri'], $uris)) {
            continue;
          }
          $tags['suggestion']['concepts'][] = $tag;
          $uris[] = $tag['uri'];
          if ($settings['concepts_per_extraction']['slider'] <= $count++) {
            break;
          }
        }
      }
      if (!empty($suggestion['freeterms'])) {
        usort($suggestion['freeterms'], [$this, 'sortByScore']);
        $labels = [];
        $count = 1;
        foreach ($suggestion['freeterms'] as $tag) {
          if (in_array($tag['label'], $labels)) {
            continue;
          }
          $tags['suggestion']['freeterms'][] = $tag;
          $labels[] = $tag['label'];
          if ($settings['freeterms_per_extraction']['slider'] <= $count++) {
            break;
          }
        }
      }
    }

    if (empty($tags['messages']) && empty($tags['suggestion']['concepts']) && empty($tags['suggestion']['freeterms'])) {
      $tags['messages'][] = [
        'type' => 'info',
        'message' => t('No concepts or free terms could be extracted from the entity\'s content.'),
      ];
    }

    $this->result = $tags;

    return empty($tags['messages']);
  }

  /**
   * Extracts the tags from the extraction result set.
   *
   * @param object $extraction
   *   The extraction result set.
   * @param array $settings
   *   The settings for threshold, concepts_per_extraction, ...
   *
   * @return array
   *   A list of found concepts and free terms with the corresponding taxonomy
   *   IDs if available.
   */
  protected function extractTags($extraction, $settings) {
    $concepts = [];
    $free_terms = [];
    $tags = [
      'concepts' => [],
      'freeterms' => [],
    ];

    // Go through the concepts.
    if (isset($extraction->concepts) && !empty($extraction->concepts)) {
      // Ignore all concepts with the score less than the threshold.
      $threshold = (int) $settings['concepts_threshold']['slider'];

      foreach ($extraction->concepts as $concept) {
        if ($concept->score >= $threshold) {
          $concepts[] = $concept;
        }
      }

      // Get the corresponding taxonomy term id.
      $this->addTermId($concepts, $settings['taxonomy_id'], 'concepts', $settings['entity_language']);

      // Ignore all not found taxonomy terms.
      if (!empty($concepts)) {
        foreach ($concepts as $concept) {
          $tags['concepts'][] = [
            'tid' => isset($concept->tid) ? $concept->tid : 0,
            'uri' => $concept->uri,
            'label' => $concept->prefLabel,
            'score' => $concept->score,
            'type' => 'concept',
          ];
        }
      }
    }

    // Go through the free terms.
    if (isset($extraction->freeTerms) && !empty($extraction->freeTerms)) {
      // Ignore all free terms with the score less than the threshold.
      $threshold = (int) $settings['freeterms_threshold']['slider'];
      foreach ($extraction->freeTerms as $free_term) {
        if ($free_term->score >= $threshold) {
          $free_terms[] = $free_term;
        }
      }

      // Get the corresponding taxonomy term id.
      $this->addTermId($free_terms, $settings['taxonomy_id'], 'free_terms', $settings['entity_language']);

      if (!empty($free_terms)) {
        foreach ($free_terms as $free_term) {
          $tags['freeterms'][] = [
            'tid' => isset($free_term->tid) ? $free_term->tid : 0,
            'uri' => '',
            'label' => $free_term->textValue,
            'score' => $free_term->score,
            'type' => 'freeterm',
          ];
        }
      }
    }

    return $tags;
  }

  /**
   * Gets concept suggestions.
   *
   * @param string $string
   *   The search string.
   *
   * @param $langcode
   *   The language.
   */
  public function suggest($string, $langcode) {
    $project_settings = $this->config_settings['project'];
    $suggested_concepts = [];
    if (!empty($project_settings['languages'][$langcode])) {
      $suggested_concepts = $this->PPXApi->suggest($string, $project_settings['languages'][$langcode], $this->config->getProjectId());
      $this->addTermId($suggested_concepts, $project_settings['taxonomy_id'], 'concepts', $langcode);
    }
    $this->result = $suggested_concepts;
  }

  /**
   * Update the powertagging tags of one powertagging field of a single entity.
   *
   * @param array $entities
   *   An array of entities.
   * @param string $field_type
   *   The field type of the powertagging field.
   * @param array $tag_settings
   *   An array of settings used during the process of extraction.
   * @param array $context
   *   The Batch context to transmit data between different calls.
   */
  public function tagEntities(array $entities, $field_type, array $tag_settings, &$context) {
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
        if (!$entity->hasField($tag_field_name) ||
          $entity->get($tag_field_name)->count() == 0
        ) {
          continue;
        }

        foreach ($entity->get($tag_field_name)->getValue() as $value) {
          switch ($tag_type['module']) {
            case 'core':
              $tag_content = trim(strip_tags($value['value']));
              if (!empty($tag_content)) {
                $tag_contents[] = $tag_content;
              }
              break;

            case 'text':
              $tag_content = trim(strip_tags($value['value']));
              if ($tag_type['widget'] == 'text_textarea_with_summary') {
                $tag_summary = trim(strip_tags($value['summary']));
                if (!empty($tag_summary) && $tag_summary != $tag_content) {
                  $tag_contents[] = $tag_summary;
                }
              }
              if (!empty($tag_content)) {
                $tag_contents[] = $tag_content;
              }
              break;

            // TODO: Add core media support.
            case 'file':
              $file_ids[] = $value['target_id'];
              break;
          }
        }
      }

      // Get the concepts for the entity.
      $tag_settings['entity_language'] = $entity->language()->getId();
      $this->extract(implode(' ', $tag_contents), $file_ids, $tag_settings);
      $extraction_result = $this->getResult();

      // Add already existing terms from default tags field if required.
      if (!empty($tag_settings['default_tags_field']) &&
        $entity->hasField($tag_settings['default_tags_field']) &&
        $entity->get($tag_settings['default_tags_field'])->count()
      ) {
        $field_values = $entity->get($tag_settings['default_tags_field'])
          ->getValue();
        $default_terms_ids = [];
        foreach ($field_values as $field_value) {
          $default_terms_ids[] = $field_value['target_id'];
        }

        $terms = Term::loadMultiple($default_terms_ids);
        /** @var Term $term */
        foreach ($terms as $term) {
          $low_term_name = strtolower($term->getName());
          $unique = TRUE;
          foreach ($extraction_result['suggestion']['concepts'] as $concept) {
            if (strtolower($concept['label']) == $low_term_name) {
              $unique = FALSE;
              break;
            }
          }
          if ($unique) {
            foreach ($extraction_result['suggestion']['freeterms'] as $freeterm) {
              if (strtolower($freeterm['label']) == $low_term_name) {
                $unique = FALSE;
                break;
              }
            }
            if ($unique) {
              $extraction_result['suggestion']['freeterms'][] = [
                'tid' => 0,
                'uri' => '',
                'label' => $term->getName(),
                'score' => 100,
                'type' => 'freeterm',
              ];
            }
          }
        }
      }

      // Update the vocabulary.
      $tags = $this->updateTaxonomyTerms($extraction_result, $tag_settings['taxonomy_id'], $tag_settings['entity_language']);

      // Set the new taxonomy terms and save the entity.
      $entity->set($field_type, $tags);
      $entity->save();

      $context['results']['tagged']++;
    }
  }

  /**
   * Updates the taxonomy terms with URIs from a PowerTagging configuration.
   *
   * @param array $terms
   *   An array of taxonomy terms.
   * @param array $context
   *   The Batch context to transmit data between different calls.
   */
  public function updateVocabulary(array $terms, &$context) {

    $existing_terms_by_uri = [];
    /** @var Term $existing_term */
    foreach ($terms as $existing_term) {
      if ($existing_term->hasField('field_uri') &&
        $existing_term->get('field_uri')->count()
      ) {
        $existing_terms_by_uri[$existing_term->get('field_uri')
          ->getString()] = $existing_term;
      }
    }

    $updated_this_batch_count = 0;
    if (!empty($existing_terms_by_uri)) {
      $concepts_details = $this->getConceptsDetails(array_keys($existing_terms_by_uri));
      foreach ($concepts_details as $concepts_detail) {
        if (isset($existing_terms_by_uri[$concepts_detail['uri']])) {
          $existing_term = $existing_terms_by_uri[$concepts_detail['uri']];
          $term_data_changed = $this->updateTaxonomyTermDetails($existing_term, (object) $concepts_detail);
          // Only save the taxonomy term if any information has changed.
          if ($term_data_changed && $existing_term->save()) {
            $updated_this_batch_count++;
          }
        }
      }
    }

    $context['results']['processed'] += count($terms);
    $context['results']['updated'] += $updated_this_batch_count;
    $context['results']['skipped'] += (count($terms) - $updated_this_batch_count);
  }

  /**
   * Get the taxonomy term ids of an extraction result.
   *
   * @param array $extraction_result
   *   The extracted terms (result of powertagging_extract()).
   * @param int $vid
   *   The ID of the vocabulary to save the terms in.
   * @param string $langcode
   *   The language of terms that need to be created.
   * @param boolean $update_existing_terms
   *   If this parameter is TRUE, the PPT API will be used to get the newest
   *   data of existing taxonomy terms and update them in case they are out of
   *   date.
   *
   * @return array
   *   Array of taxonomy term ids for the extracted concepts.
   */
  public function updateTaxonomyTerms(array $extraction_result, $vid, $langcode, $update_existing_terms = TRUE) {
    $tids = [];
    $tags = [];
    $new_terms = [];
    $new_terms_score = [];

    // Add tids of concepts.
    foreach ($extraction_result['suggestion']['concepts'] as $concept) {
      if ($concept['tid'] > 0) {
        $tids[] = $concept['tid'];
        $tags[] = ['target_id' => $concept['tid'], 'score' => $concept['score']];
      }
      else {
        $term = $concept['label'] . '|' . $concept['uri'];
        $new_terms[] = $term;
        $new_terms_score[$term] = $concept['score'];
      }
    }
    // Add tids of freeterms.
    foreach ($extraction_result['suggestion']['freeterms'] as $concept) {
      if ($concept['tid'] > 0) {
        $tids[] = $concept['tid'];
        $tags[] = ['target_id' => $concept['tid'], 'score' => $concept['score']];
      }
      else {
        $term = $concept['label'] . '|' . $concept['uri'];
        $new_terms[] = $term;
        $new_terms_score[$term] = $concept['score'];      }
    }

    // Update existing taxonomy terms if required.
    if (count($tids) && $update_existing_terms) {
      $terms = Term::loadMultiple($tids);
      $existing_terms_by_uri = [];
      /** @var Term $existing_term */
      foreach ($terms as $existing_term) {
        if ($existing_term->hasField('field_uri') &&
          $existing_term->get('field_uri')->count()
        ) {
          $uri = $existing_term->get('field_uri')->getString();
          $existing_terms_by_uri[$uri] = $existing_term;
        }
      }

      if (!empty($existing_terms_by_uri)) {
        $concepts_detail_data = $this->getConceptsDetails(array_keys($existing_terms_by_uri), $langcode);
        foreach ($concepts_detail_data as $concept_detail_data) {
          if (isset($existing_terms_by_uri[$concept_detail_data['uri']])) {
            $existing_term = $existing_terms_by_uri[$concept_detail_data['uri']];
            $term_data_changed = $this->updateTaxonomyTermDetails($existing_term, (object) $concept_detail_data);
            // Only save the taxonomy term if any information has changed.
            if ($term_data_changed) {
              $existing_term->save();
            }
          }
        }
      }
    }

    // Create taxonomy terms for new tags.
    if (count($new_terms)) {
      $new_term_ids = $this->addTaxonomyTerms($vid, $new_terms, $langcode);
      // Merge existing and new terms.
      foreach ($new_term_ids as $term => $new_term_id) {
        $tags[] = ['target_id' => $new_term_id, 'score' => $new_terms_score[$term]];
      }
    }

    return $tags;
  }

  /**
   * Get detail information for a list of concept URIs.
   *
   * @param array $uris
   *   An Array or URIs of the concepts.
   * @param string $langcode
   *   The language of the concepts.
   *
   * @return array An array of concept detail information.
   *   An array of concept detail information.
   */
  public function getConceptsDetails(array $uris, $langcode = '') {
    $concepts = $this->config->getConnection()
      ->getApi('PPT')
      ->getConcepts($this->config->getProjectId(), $uris, [
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
  public function updateTaxonomyTermDetails(Term &$term, $concept_details) {
    $data_has_changed = FALSE;

    // Set the taxonomy name.
    if (!empty($concept_details->prefLabel)) {
      if ($term->getName() != $concept_details->prefLabel) {
        $data_has_changed = TRUE;
        $term->setName($concept_details->prefLabel);
      }
    }

    // Set the URI.
    if (!empty($concept_details->uri)) {
      if ($term->get('field_uri')->getString() != $concept_details->uri) {
        $data_has_changed = TRUE;
        $term->get('field_uri')->setValue($concept_details->uri);
      }
    }

    // Set the description.
    $term_description = $term->getDescription();
    if (!empty($concept_details->definitions)) {
      $description = '<p>' . implode('</p><p>', $concept_details->definitions) . '</p>';
      if ($term_description != $description) {
        $data_has_changed = TRUE;
        $term->setDescription($description);
      }
    }
    elseif (!empty($term_description)) {
        $data_has_changed = TRUE;
        $term->setDescription('');
    }

    // Set alternative labels.
    $term_alt_labels = $term->get('field_alt_labels')->getString();
    if (!empty($concept_details->altLabels)) {
      $alt_labels = implode(',', $concept_details->altLabels);
      if ($term_alt_labels != $alt_labels) {
        $data_has_changed = TRUE;
        $term->get('field_alt_labels')->setValue($alt_labels);
      }
    }
    elseif (!empty($term_alt_labels)) {
        $data_has_changed = TRUE;
        $term->get('field_alt_labels')->setValue('');
    }

    // Set hidden labels.
    $term_hidden_labels = $term->get('field_hidden_labels')->getString();
    if (isset($concept_details->hiddenLabels)) {
      $hidden_labels = implode(',', $concept_details->hiddenLabels);
      if ($term_hidden_labels != $hidden_labels) {
        $data_has_changed = TRUE;
        $term->get('field_hidden_labels')->setValue($hidden_labels);
      }
    }
    elseif (!empty($term_hidden_labels)) {
        $data_has_changed = TRUE;
        $term->get('field_hidden_labels')->setValue('');
    }

    // Set exact match values.
    if (!empty($concept_details->exactMatch)) {
      $concept_count = count($concept_details->exactMatch);
      $term_count = $term->get('field_exact_match')->count();

      if ($concept_count != $term_count) {
        $term->get('field_exact_match')->setValue(NULL);
      }
      for ($i = 0; $i < $concept_count; $i++) {
        if (!$term->get('field_exact_match')->get($i) ||
          $term->get('field_exact_match')
            ->get($i)
            ->getString() != $concept_details->exactMatch[$i]
        ) {
          $data_has_changed = TRUE;
          $term->get('field_exact_match')
            ->set($i, $concept_details->exactMatch[$i]);
        }
      }
    }
    elseif (!empty($term->get('field_exact_match')->getString())) {
        $data_has_changed = TRUE;
        $term->get('field_exact_match')->setValue(NULL);
    }

    return $data_has_changed;
  }

  /**
   * Add new concepts or freeterms to the vocabulary of a PoolParty project.
   *
   * @param string $vid
   *   The vocabulary ID in which the new tag must be stored.
   * @param array $new_terms
   *   Array of strings of new terms to add.
   * @param string $langcode
   *   The Drupal language of the terms to add.
   *
   * @return array
   *   Array of created term-ids.
   */
  public function addTaxonomyTerms($vid, array $new_terms, $langcode) {
    $term_ids = [];
    $parent = $this->getTermListIds($vid, $langcode);

    // Collect all the URIs and get the concept details of it.
    $new_uris = [];
    foreach ($new_terms as $new_term) {
      list(, $uri) = explode('|', $new_term);
      if (!empty($uri)) {
        $new_uris[] = $uri;
      }
    }
    $concepts_details = $this->getConceptsDetails($new_uris, $langcode);
    $concepts_details_by_uri = [];
    foreach ($concepts_details as $concepts_detail) {
      $concepts_details_by_uri[$concepts_detail['uri']] = (object) $concepts_detail;
    }
    $concepts_details = $concepts_details_by_uri;
    unset($concepts_details_by_uri);

    // Go through all new tags.
    foreach ($new_terms as $new_term) {
      list($label, $uri) = explode('|', $new_term);

      // Check if the term already exists.
      $old_term = \Drupal::entityQuery('taxonomy_term')
        ->condition('name', $label)
        ->condition('vid', $vid)
        ->condition('langcode', $langcode)
        ->execute();

      // If the term already exists and the entered term has no URI then do
      // nothing --> This case should never appear.
      if (!empty($old_term) && empty($uri)) {
        $term_ids[$new_term] = array_shift($old_term);
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
        $this->updateTaxonomyTermDetails($term, $concepts_details[$uri]);
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
        ->fields(['tid', 'parent'])
        ->values(['tid' => $term->id(), 'parent' => $parent_id])
        ->execute();

      $term_ids[$new_term] = $term->id();
    }

    return $term_ids;
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
    $list_ids = [
      'concepts' => NULL,
      'freeterms' => NULL,
    ];
    $list_names = [
      'Concepts' => 'concepts',
      'Free Terms' => 'freeterms',
    ];

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
   * Get all powertagging field instances.
   *
   * @return FieldConfig[]
   *   Array of field instances that match the filters.
   */
  public function getTaggingFieldInstances() {
    $fields = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->loadByProperties(['type' => 'powertagging_tags']);

    $field_options = [];
    /** @var FieldStorageConfig $field_data */
    foreach ($fields as $field_data) {
      if ($field_data->getSetting('powertagging_id') != $this->config->id()) {
        continue;
      }

      $field_instances = \Drupal::entityTypeManager()
        ->getStorage('field_config')
        ->loadByProperties(['field_name' => $field_data->getName()]);
      /** @var FieldConfig $field_instance */
      foreach ($field_instances as $field_instance) {
        if ($this->checkFieldInstance($field_instance)) {
          $field_options[] = $field_instance;
        }
      }
    }

    return $field_options;
  }

  /**
   * Check if a powertagging-field-instance is correctly configured to allow
   * tags.
   *
   * @param FieldConfig $instance
   *   The field instance to check.
   *
   * @return bool
   *   TRUE if the field instance was configured correctly, FALSE if not.
   */
  protected function checkFieldInstance($instance) {
    if ($instance->getType() == 'powertagging_tags') {
      // Check if the "Number of values" was set to "Unlimited".
      $storage = $instance->getFieldStorageDefinition();
      if ($storage->getCardinality() == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
        // Check if fields are set.
        $fields_to_check = [];
        foreach ($instance->getSetting('fields') as $field_id) {
          if ($field_id != FALSE) {
            $fields_to_check[] = $field_id;
          }
        }
        if (!empty($fields_to_check)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Returns a list with Power Tagging fields as a option list.
   *
   * @param FieldConfig[] $field_instances
   *   An array of field instances with "PowerTagging Tags" fields.
   *
   * @param boolean $add_fieldname (optional)
   *   Adds the machine name of the field if the value is TRUE.
   *
   * @return array
   *   Option list with Power Tagging fields.
   */
  public function getTaggingFieldOptionsList($field_instances, $add_fieldname = FALSE) {
    $content_type_options = [];
    if (!empty($field_instances)) {
      $node_type_names = node_type_get_names();
      $taxonomy_names = taxonomy_vocabulary_get_names();

      /** @var FieldConfig $field_instance */
      foreach ($field_instances as $field_instance) {
        $option_title = '';
        // Build the title of the option.
        switch ($field_instance->getTargetEntityTypeId()) {
          case 'node':
            $option_title = t('Content type "@name"', ['@name' => $node_type_names[$field_instance->getTargetBundle()]]);
            break;

          case 'user':
            $option_title = t('User');
            break;

          case 'taxonomy_term':
            $option_title = t('Vocabulary "@name"', ['@name' => $taxonomy_names[$field_instance->getTargetBundle()]->name]);
            break;

          default:
            // If the entity type is not supported, throw an error and continue.
            drupal_set_message(t('Entity type "%entitytype" is not supported.', ['%entitytype' => $field_instance->getTargetEntityTypeId()]), 'warning');
            continue;
        }
        if ($add_fieldname) {
          $option_title .= ' (' . $field_instance->getName() . ')';
        }

        // Add the option.
        $content_type_options[$field_instance->getTargetEntityTypeId() . ' ' . $field_instance->getTargetBundle() . ' ' . $field_instance->getName()] = $option_title;
      }
    }

    return $content_type_options;
  }

  /**
   * Add the corresponding taxonomy term id to the concepts or free terms.
   *
   * @param array $concepts
   *   The concepts or free terms found from PP Extractor.
   * @param string $vid
   *   The taxonomy id in which the taxonomy is imported.
   * @param string $type
   *   The type of the concepts (concepts or free terms).
   * @param string $langcode
   *   The language of the concept label.
   */
  protected function addTermId(array &$concepts, $vid, $type, $langcode) {
    if (empty($concepts)) {
      return;
    }

    switch ($type) {
      case 'concepts':
        // Get all concept uris.
        $uris = [];
        foreach ($concepts as $concept) {
          $uris[] = $concept->uri;
        }

        // Search for the corresponding tids.
        $tids = \Drupal::entityQuery('taxonomy_term')
          ->condition('vid', $vid)
          ->condition('langcode', $langcode)
          ->condition('field_uri', $uris, 'IN')
          ->execute();

        // Create map list from URI to tid.
        $result = Term::loadMultiple($tids);
        $terms = [];
        /** @var Term $term */
        foreach ($result as $term) {
          $terms[$term->get('field_uri')->getString()] = [
            'tid' => $term->id(),
          ];
        }

        // Add the tid to each concept if exists.
        foreach ($concepts as &$concept) {
          if (isset($terms[$concept->uri])) {
            $concept->tid = $terms[$concept->uri]['tid'];
          }
          else {
            $concept->tid = 0;
          }
        }
        break;

      case 'free_terms':
        // Get all concept uris.
        $labels = [];
        foreach ($concepts as $concept) {
          $labels[] = $concept->textValue;
        }

        // Search for the corresponding tids.
        $tids = \Drupal::entityQuery('taxonomy_term')
          ->condition('vid', $vid)
          ->condition('langcode', $langcode)
          ->condition('name', $labels, 'IN')
          ->execute();

        // Create map list from label to tid.
        $result = Term::loadMultiple($tids);
        $terms = [];
        /** @var Term $term */
        foreach ($result as $term) {
          $terms[$term->getName()] = [
            'tid' => $term->id(),
          ];
        }

        // Add the tid to each concept if exists.
        foreach ($concepts as &$concept) {
          if (isset($terms[$concept->textValue])) {
            $concept->tid = $terms[$concept->textValue]['tid'];
          }
          else {
            $concept->tid = 0;
          }
        }
        break;
    }

  }

  /**
   * Callback function for sorting tags by score.
   */
  protected function sortByScore($a, $b) {
    if ($a['score'] == $b['score']) {
      return 0;
    }
    return ($a['score'] < $b['score']) ? 1 : -1;
  }
}
