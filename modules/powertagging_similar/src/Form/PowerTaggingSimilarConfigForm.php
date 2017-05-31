<?php

/**
 * @file
 * Contains \Drupal\powertagging_similar\Form\PowerTaggingSimilarConfigForm.
 */

namespace Drupal\powertagging_similar\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\PowerTagging;
use Drupal\powertagging_similar\Entity\PowerTaggingSimilarConfig;

class PowerTaggingSimilarConfigForm extends EntityForm {
  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var PowerTaggingSimilarConfig $entity */
    $entity = $this->entity;

    $configuration = $entity->getConfig();

    $connection_overrides = \Drupal::config('semantic_connector.settings')->get('override_connections');
    $overridden_values = array();
    if (isset($connection_overrides[$entity->id()])) {
      $overridden_values = $connection_overrides[$entity->id()];
    }

    $form['title'] = array(
      '#type' => 'textfield',
      '#title' => t('Name'),
      '#description' => t('Name of the PowerTagging Similar Content widget.'). (isset($overridden_values['title']) ? ' <span class="semantic-connector-overridden-value">' . t('Warning: overridden by variable') . '</span>' : ''),
      '#size' => 35,
      '#maxlength' => 255,
      '#default_value' => $entity->getTitle(),
      '#required' => TRUE,
    );

    $powertagging_configs = PowerTaggingConfig::loadMultiple();
    $powertagging_ids = array();
    /** @var PowerTaggingConfig $powertagging_config */
    foreach ($powertagging_configs as $powertagging_config) {
      $powertagging_ids[$powertagging_config->id()] = $powertagging_config->getTitle();
    }

    $form['powertagging_id'] = array(
      '#type' => 'select',
      '#title' => t('PowerTagging Configuration'),
      '#options' => $powertagging_ids,
      '#required' => TRUE,
      '#default_value' => ($entity->getPowerTaggingId() > 0 ? $entity->getPowerTaggingId() : key($powertagging_ids)),
    );

    $form['content_types']['#tree'] = TRUE;
    foreach ($powertagging_configs as $powertagging_config) {
      $powertagging_id = $powertagging_config->id();
      $powertagging = new PowerTagging($powertagging_config);
      $field_instances = $powertagging->getTaggingFieldInstances();
      $fields = $powertagging->getTaggingFieldOptionsList($field_instances);

      $form['content_types'][$powertagging_id] = array(
        '#type' => 'item',
        '#states' => array(
          'visible' => array(
            ':input[name="powertagging_id"]' => array('value' => $powertagging_id),
          ),
        ),
      );

      // Content types available containing PowerTagging fields.
      if (!empty($fields)) {
        $weighted_content_types = array();
        $added_field_keys = array();

        // Add existing configuration first.
        if (!empty($configuration['content_types']) && isset($configuration['content_types'][$powertagging_id])) {
          foreach ($configuration['content_types'][$powertagging_id] as $content_type) {
            // Check if this content type still exists.
            if (isset($fields[$content_type['entity_key']])) {
              $content_type['entity_label'] = $fields[$content_type['entity_key']];
              $weighted_content_types[] = $content_type;
              $added_field_keys[] = $content_type['entity_key'];
            }
          }
        }

        // Add new content configuration at the end of the list.
        foreach ($fields as $field_keys => $field_label) {
          if (!in_array($field_keys, $added_field_keys)) {
            $weighted_content_types[] = array(
              'entity_key' => $field_keys,
              'entity_label' => $field_label,
              'show' => FALSE,
              'title' => '',
              'count' => 5,
            );
          }
        }

        foreach ($weighted_content_types as $weight => $content_type) {
          $key = $content_type['entity_key'];
          $form['content_types'][$powertagging_id]['content'][$key]['node'] = array(
            '#markup' => $content_type['entity_label'],
          );

          // This field is invisible, but contains sort info (weights).
          $form['content_types'][$powertagging_id]['content'][$key]['weight'] = array(
            '#type' => 'weight',
            // Weights from -255 to +255 are supported because of this delta.
            '#delta' => 255,
            '#title_display' => 'invisible',
            '#default_value' => $weight,
          );

          $form['content_types'][$powertagging_id]['content'][$key]['show'] = array(
            '#type' => 'checkbox',
            '#default_value' => $content_type['show'],
          );

          $form['content_types'][$powertagging_id]['content'][$key]['title'] = array(
            '#type' => 'textfield',
            '#default_value' => $content_type['title'],
            '#states' => array(
              'disabled' => array(
                ':input[name="merge_content"]' => array('checked' => TRUE),
              ),
            ),
          );

          $form['content_types'][$powertagging_id]['content'][$key]['count'] = array(
            '#type' => 'select',
            '#options' => array_combine(range(1, 10),range(1, 10)),
            '#default_value' => $content_type['count'],
            '#states' => array(
              'disabled' => array(
                ':input[name="merge_content"]' => array('checked' => TRUE),
              ),
            ),
          );
        }
      }
      // No content type available.
      else {
        $form['content_types'][$powertagging_id]['title'] = array(
          '#type' => 'markup',
          '#markup' => t('No content type is connected to this PowerTagging configuration.'),
        );
      }
    }

    $form['display_type'] = array(
      '#type' => 'select',
      '#title' => t('Content to display'),
      '#description' => t('How to display the items in the list of similar content.'),
      '#options' => array(
        'default' => 'Title as a link (default)',
        'view_mode' => 'Customized display ("Powertagging similar content" view mode)'
      ),
      '#default_value' => $configuration['display_type'],
    );

    $form['merge_content'] = array(
      '#type' => 'checkbox',
      '#title' => t('Merge content'),
      '#description' => t('Display all content types in a single content list.'),
      '#default_value' => $configuration['merge_content'],
    );

    $form['merge_content_count'] = array(
      '#type' => 'select',
      '#title' => t('Number of items to display'),
      '#description' => t('The maximum number of similar items you want to display.'),
      '#options' => array_combine(range(1, 10),range(1, 10)),
      '#default_value' => $configuration['merge_content_count'],
      '#states' => array(
        'visible' => array(
          ':input[name="merge_content"]' => array('checked' => TRUE),
        ),
      ),
    );

    // Add CSS and JS.
    $form['#attached'] = array(
      'library' =>  array(
        'powertagging_similar/admin_area',
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var PowerTaggingSimilarConfig $entity */
    $entity = $this->entity;

    // Update and save the entity.
    $entity->set('title', $form_state->getValue('title'));
    $entity->set('config', array('max_items' => $form_state->getValue('max_items')));

    drupal_set_message(t('powertagging Similar Content widget %title has been saved.', array('%title' => $form_state->getValue('title'))));
    $entity->save();

    $form_state->setRedirectUrl(Url::fromRoute('entity.powertagging_similar.collection'));
  }

  public function element_validate_integer_positive($element, FormStateInterface $form_state) {
    $value = $element['#value'];
    if ($value !== '' && (!is_numeric($value) || intval($value) != $value || $value <= 0)) {
      $form_state->setErrorByName($element, t('%name must be a positive integer.', array('%name' => $element['#title'])));
    }
  }
}