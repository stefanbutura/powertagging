<?php

/**
 * @file
 * The main class of the PowerTagging module.
 */

namespace Drupal\powertagging;

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
    $corpus_id = isset($project_config['corpus_id']) ? $project_config['corpus_id'] : '';

    $param = [
      'projectId' => $this->config->getProjectId(),
      'numberOfConcepts' => (int) $settings['concepts_per_extraction'],
      'numberOfTerms' => (int) $settings['freeterms_per_extraction'],
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
        ], "", strip_tags($content)), ENT_COMPAT, 'UTF-8'));

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
          if ($settings['concepts_per_extraction'] <= $count++) {
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
          if ($settings['freeterms_per_extraction'] <= $count++) {
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
   * @param array $entity_ids
   *   A single ID or an array of IDs of entities, depending on the entity type
   * @param string $entity_type_id
   *   The entity type ID of the entity (e.g. node, user, ...).
   * @param string $field_type
   *   The field type of the powertagging field.
   * @param array $tag_settings
   *   An array of settings used during the process of extraction.
   * @param array $batch_info
   *   An associative array of information about the batch process.
   * @param array $context
   *   The Batch context to transmit data between different calls.
   */
  public function tagContentBatchProcess(array $entity_ids, $entity_type_id, $field_type, array $tag_settings, array $batch_info, &$context) {
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
        if (!$entity->hasField($tag_field_name)) {
          continue;
        }


        // Standard fields like "title".
        if ($tag_type['module'] == 'standard field') {
          $tag_contents[] = trim(strip_tags($entity->{$tag_field_name}));
        }
        // Custom fields
        else {
          if (empty($entity->{$tag_field_name})) {
            continue;
          }
          $language_keys = array_keys($entity->{$tag_field_name});
          $field_value_language = $entity->{$tag_field_name}[$language_keys[0]];
          if (!empty($field_value_language) && is_array($field_value_language[0])) {
            switch ($tag_type['module']) {
              case 'text':
                $tag_content = trim(strip_tags($field_value_language[0]['value']));
                if ($tag_type['type'] == 'text_textarea_with_summary') {
                  $tag_summary = trim(strip_tags($field_value_language[0]['summary']));
                  if ($tag_summary != $tag_content) {
                    $tag_contents[] = $tag_summary;
                  }
                }
                $tag_contents[] = $tag_content;
                break;

              case 'file':
              case 'media':
                if (isset($field_value_language[0]['fid'])) {
                  foreach ($field_value_language as $file) {
                    $file_ids[] = $file['fid'];
                  }
                }
                break;
            }
          }
        }
      }

      // Get the language of the entity.
      $tag_settings['entity_language'] = (powertagging_translation_possible() && isset($entity->language)) ? $entity->language : LANGUAGE_NONE;

      // Get the concepts for the entity.
      $extraction_result = powertagging_extract(implode(' ', $tag_contents), $file_ids, $tag_settings, 'array');

      // Add already existing terms if required.
      if (isset($tag_settings['default_tags_field']) && !empty($tag_settings['default_tags_field']) &&
        isset($entity->{$tag_settings['default_tags_field']}) && !empty($entity->{$tag_settings['default_tags_field']})
      ) {
        $language_keys = array_keys($entity->{$tag_settings['default_tags_field']});
        $field_values = $entity->{$tag_settings['default_tags_field']}[$language_keys[0]];
        if (!empty($field_values)) {
          $default_terms_ids = [];
          foreach ($field_values as $field_value) {
            if (isset($field_value['tid'])) {
              $default_terms_ids[] = $field_value['tid'];
            }
          }

          $terms = taxonomy_term_load_multiple($default_terms_ids);
          foreach ($terms as $term) {
            $low_term_name = strtolower($term->name);
            $unique = TRUE;
            foreach ($extraction_result['suggestion']['concepts'] as $concept) {
              if (strtolower($concept['label']) == $low_term_name) {
                $unique = FALSE;
              }
            }
            if ($unique) {
              foreach ($extraction_result['suggestion']['freeterms'] as $freeterm) {
                if (strtolower($freeterm['label']) == $low_term_name) {
                  $unique = FALSE;
                }
              }
              if ($unique) {
                $extraction_result['suggestion']['freeterms'][] = [
                  'tid' => 0,
                  'uri' => '',
                  'label' => $term->name,
                  'score' => 100,
                  'type' => 'freeterm',
                ];
              }
            }
          }
        }
      }

      $tids = powertagging_extraction_result_to_tids($tag_settings['powertagging_config'], $extraction_result, $tag_settings['taxonomy_id'], $tag_settings['entity_language']);

      // Bring the tids into the correct format for a entity.
      $field_value_tids = [];
      foreach ($tids as $tid) {
        $field_value_tids[] = [
          'tid' => (string) $tid,
        ];
      }

      // Save the PowerTagging tags to the entity.
      switch ($entity_type) {
        case 'node':
          if (!empty($field_value_tids)) {
            $entity->{$field_name}[LANGUAGE_NONE] = $field_value_tids;
          }
          else {
            $entity->{$field_name} = [];
          }
          node_save($entity);

          //drupal_set_message(t('Node "%entitytitle" was retagged successfully.', array('%entitytitle' => $entity->title)));
          break;

        case 'user':
          $user_edit_data = [];
          if (!empty($field_value_tids)) {
            $user_edit_data[$field_name][LANGUAGE_NONE] = $field_value_tids;
          }
          else {
            $user_edit_data[$field_name] = [];
          }
          user_save($entity, $user_edit_data);

          //drupal_set_message(t('User "%entitytitle" was retagged successfully.', array('%entitytitle' => $entity->name)));
          break;

        case 'taxonomy_term':
          if (!empty($field_value_tids)) {
            $entity->{$field_name}[LANGUAGE_NONE] = $field_value_tids;
          }
          else {
            $entity->{$field_name} = [];
          }
          taxonomy_term_save($entity);

          //drupal_set_message(t('Taxonomy term "%entitytitle" was retagged successfully.', array('%entitytitle' => $entity->name)));
          break;
      }

      $context['results']['tagged']++;
    }

    // Show the remaining time as a batch message.
    $time_string = '';
    if ($context['results']['processed'] > 0) {
      $remaining_time = floor((time() - $batch_info['start_time']) / $context['results']['processed'] * ($batch_info['total'] - $context['results']['processed']));
      if ($remaining_time > 0) {
        $time_string = (floor($remaining_time / 86400)) . 'd ' . (floor($remaining_time / 3600) % 24) . 'h ' . (floor($remaining_time / 60) % 60) . 'm ' . ($remaining_time % 60) . 's';
      }
      else {
        $time_string = t('Done.');
      }
    }

    $context['message'] = t('Processed entities: %currententities of %totalentities. (Tagged: %taggedentities, Skipped: %skippedentities)', [
          '%currententities' => $context['results']['processed'],
          '%taggedentities' => $context['results']['tagged'],
          '%skippedentities' => $context['results']['skipped'],
          '%totalentities' => $batch_info['total'],
        ]
      ) . '<br />' . t('Remaining time: %remainingtime.', ['%remainingtime' => $time_string]);
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
      $threshold = (int) $settings['concepts_threshold'];

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
      $threshold = (int) $settings['freeterms_threshold'];
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
