<?php

/**
 * @file Contains \Drupal\powertagging\Form\PowerTaggingConfigConnectionForm.
 */

namespace Drupal\powertagging\Form;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\semantic_connector\SemanticConnector;
use Drupal\powertagging\Entity\PowerTaggingConfig;

/**
 * Class PowerTaggingConfigConnectionForm.
 *
 * @package Drupal\powertagging\Form
 */
class PowerTaggingConfigConnectionForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var PowerTaggingConfig $powertagging */
    $powertagging = $this->entity;

    if ($powertagging->isNew()) {
      $form['title'] = [
        '#type' => 'textfield',
        '#title' => t('Name'),
        '#description' => t("Name of the PowerTagging configuration."),
        '#size' => 35,
        '#maxlength' => 255,
        '#default_value' => $powertagging->getTitle(),
        '#required' => TRUE,
        '#validated' => TRUE,
      ];
    }
    else {
      $form['title'] = [
        '#type' => 'hidden',
        '#value' => $powertagging->getTitle(),
      ];
    }

    $connection_overides = \Drupal::config('semantic_connector.settings')->get('override_connections');
    $overridden_values = [];
    if ($powertagging->isNew() && isset($connection_overides[$powertagging->id()])) {
      $overridden_values = $connection_overides[$powertagging->id()];
    }

    // Container: 1. Server settings.
    $form['server_settings'] = [
      '#type' => 'fieldset',
      '#title' => t('1. Select the PoolParty server to use'),
    ];

    if (isset($overridden_values['connection_id'])) {
      $form['server_settings']['connection_id'] = [
        '#markup' => '<span class="semantic-connector-overridden-value">' . t('Warning: overridden by variable') . '</span>',
      ];
    }

    $connections = SemanticConnector::getConnectionsByType('pp_server');
    if (!empty($connections)) {
      $connection_options = [];
      /** @var \Drupal\semantic_connector\Entity\SemanticConnectorPPServerConnection $connection */
      foreach ($connections as $connection) {
        $credentials = $connection->getCredentials();
        $key = implode('|', array($connection->getTitle(), $connection->getUrl(), $credentials['username'], $credentials['password']));
        $connection_options[$key] = $connection->getTitle();
      }
      $form['server_settings']['load_connection'] = [
        '#type' => 'select',
        '#title' => t('Load an available PoolParty server'),
        '#options' => $connection_options,
        '#empty_option' => '',
        '#default_value' => '',
      ];
    }

    // Container: Connection details.
    $connection = $powertagging->getConnection();
    $form['server_settings']['connection_details'] = [
      '#type' => 'fieldset',
      '#title' => t('Connection details'),
    ];

    $form['server_settings']['connection_details']['connection_id'] = [
      '#type' => 'hidden',
      '#value' => $connection->id(),
    ];

    $form['server_settings']['connection_details']['server_title'] = [
      '#type' => 'textfield',
      '#title' => t('Server title'),
      '#description' => t('A short title for the server below.'),
      '#size' => 35,
      '#maxlength' => 60,
      '#default_value' => $connection->getTitle(),
      '#required' => TRUE,
    ];

    $form['server_settings']['connection_details']['url'] = [
      '#type' => 'url',
      '#title' => t('URL'),
      '#description' => t('URL, where the PoolParty server runs, without path information.'),
      '#size' => 35,
      '#maxlength' => 255,
      '#default_value' => $connection->getUrl(),
      '#required' => TRUE,
    ];

    $credentials = $connection->getCredentials();
    $form['server_settings']['connection_details']['credentials'] = [
      '#type' => 'details',
      '#title' => t('Credentials'),
      '#open' => FALSE,
    ];
    $form['server_settings']['connection_details']['credentials']['username'] = [
      '#type' => 'textfield',
      '#title' => t('Username'),
      '#description' => t('Name of a user for the credentials.'),
      '#size' => 35,
      '#maxlength' => 60,
      '#default_value' => $credentials['username'],
    ];
    $form['server_settings']['connection_details']['credentials']['password'] = [
      '#type' => 'textfield',
      '#title' => t('Password'),
      '#description' => t('Password of a user for the credentials.'),
      '#size' => 35,
      '#maxlength' => 128,
      '#default_value' => $credentials['password'],
    ];
    $form['server_settings']['health_check'] = [
      '#type' => 'button',
      '#value' => t('Health check'),
      '#ajax' => [
        'callback' => '::connectionTest',
        'wrapper' => 'health_info',
        'method' => 'replace',
        'effect' => 'slide',
        'progress' => [
          'type' => 'throbber',
          'message' => t('Testing the connection...'),
        ],
      ],
    ];

    if ($powertagging->isNew()) {
      $markup = '<div id="health_info">' . t('Click to check if the server is available.') . '</div>';
    }
    else {
      $available = '<div id="health_info" class="available">' . t('The server is available.') . '</div>';
      $not_available = '<div id="health_info" class="not-available">' . t('The server is not available or the credentials are incorrect.') . '</div>';
      $markup = $connection->available() ? $available : $not_available;
    }
    $form['server_settings']['health_info'] = array(
      '#markup' => $markup,
    );

    // Container: 2. Project loading.
    $form['project_load'] = [
      '#type' => 'fieldset',
      '#title' => t('2. Load the projects'),
    ];
    $form['project_load']['load_projects'] = [
      '#type' => 'button',
      '#value' => t('Load projects'),
      '#ajax' => [
        'callback' => '::getProjects',
        'wrapper' => 'projects-replace',
        'progress' => [
          'type' => 'throbber',
          'message' => t('Loading projects...'),
        ],
      ],
    ];

    // Container: 3. Project selection.
    $form['project_select'] = [
      '#type' => 'fieldset',
      '#title' => t('3. Select a project'),
      '#description' => t('Note: In case this list is still empty after clicking the "Load projects" button make sure that a connection to the PoolParty server can be established and check the rights of your selected user inside PoolParty.'),
    ];

    // Get the project options for the currently configured PoolParty server.
    $project_options = array();
    if (!$powertagging->isNew()) {
      $projects = $connection->getApi('PPX')->getProjects();
      foreach ($projects as $project) {
        $project_options[$project->uuid] = $project->label;
      }
    }
    $form['project_select']['project'] = [
      '#type' => 'select',
      '#title' => t('Select a project'),
      '#prefix' => '<div id="projects-replace">',
      '#suffix' => '</div>',
      '#options' => $project_options,
      '#default_value' => (!$powertagging->isNew() ? $powertagging->getProjectId() : NULL),
      '#required' => TRUE,
      '#validated' => TRUE,
    ];
    if (isset($overridden_values['project_id'])) {
      $form['project_select']['project']['#description'] = '<span class="semantic-connector-overridden-value">' . t('Warning: overridden by variable') . '</span>';
    }

    $form['#attached'] = [
      'library' => [
        'powertagging/admin_area',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Only do project validation during the save-operation, not during
    // AJAX-requests like the health check of the server.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element['#parents'][0] == 'save') {
      if (isset($form_state['values']['url']) && UrlHelper::isValid($form_state->getValue('url'), TRUE)) {
        // Create a new connection (without saving) with the current form data.
        $connection = SemanticConnector::getConnection('pp_server');
        $connection->setUrl($form_state->getValue('url'));
        $connection->setCredentials([
          'username' => $form_state->getValue('username'),
          'password' => $form_state->getValue('password'),
        ]);

        $projects = $connection->getApi('PPX')->getProjects();
        $project_is_valid = FALSE;
        foreach ($projects as $project) {
          if ($project->uuid == $form_state->getValue('project')) {
            $project_is_valid = TRUE;
            break;
          }
        }
        if (!$project_is_valid) {
          $form_state->setErrorByName('project', t('The selected project is not available on the given PoolParty server.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var PowerTaggingConfig $powertagging */
    $powertagging = $this->entity;

    // Always create a new connection, if URL and type are the same the old one
    // will be used anyway.
    $connection = SemanticConnector::createConnection('pp_server', $form_state->getValue('url'), $form_state->getValue('server_title'), [
      'username' => $form_state->getValue('username'),
      'password' => $form_state->getValue('password'),
    ]);
    if ($powertagging->isNew()) {
      $powertagging->set('id', SemanticConnector::createUniqueEntityMachineName('powertagging', $powertagging->getTitle()));
    }
    $powertagging->set('connection_id', $connection->getId());
    $powertagging->set('project_id', $form_state->getValue('project'));

    $status = $powertagging->save();
    switch ($status) {
      case SAVED_NEW:
        drupal_set_message($this->t('PowerTagging configuration %title has been created.', [
          '%title' => $powertagging->getTitle(),
        ]));
        break;

      default:
        drupal_set_message($this->t('PowerTagging configuration %title has been updated.', [
          '%title' => $powertagging->getTitle(),
        ]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('entity.powertagging.edit_config_form', array('powertagging' => $powertagging->id())));
  }

  /**
   * Ajax callback function for checking if a new PoolParty server is available.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form_state object.
   *
   * @return array
   *   The output array to be rendered.
   */
  public function connectionTest(array &$form, FormStateInterface $form_state) {
    $available = '<div id="health_info" class="available"><div class="semantic-connector-led led-green" title="Service available"></div>' . t('The server is available.') . '</div>';
    $not_available = '<div id="health_info" class="not-available"><div class="semantic-connector-led led-red" title="Service NOT available"></div>' . t('The server is not available or the credentials are incorrect.') . '</div>';
    $markup = $not_available;

    if (!empty($form_state->getValue('url')) && UrlHelper::isValid($form_state->getValue('url'), TRUE)) {
      // Create a new connection (without saving) with the current form data.
      $connection = SemanticConnector::getConnection('pp_server');
      $connection->setUrl($form_state->getValue('url'));
      $connection->setCredentials([
        'username' => $form_state->getValue('username'),
        'password' => $form_state->getValue('password'),
      ]);

      $availability = $connection->getApi('PPX')->available();
      if (!empty($availability['message'])) {
        $markup = '<div id="health_info" class="not-available"><div class="semantic-connector-led led-red" title="Service NOT available"></div>' . $availability['message'] . '</div>';
      }
      else {
        $markup = $availability['success'] ? $available : $not_available;
      }
    }

    return [
      '#markup' => $markup,
    ];
  }

  /**
   * Ajax callback function to get a project select list for a given PoolParty
   * server connection configuration.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form_state object.
   *
   * @return array
   *   The selected form element containing the project objects for the current
   *   PoolParty server.
   */
  public function getProjects(array &$form, FormStateInterface $form_state) {
    $projects_element = $form['project_select']['project'];

    $project_options = [];
    if (!empty($form_state->getValue('url')) && UrlHelper::isValid($form_state->getValue('url'), TRUE)) {
      // Create a new connection (without saving) with the current form data.
      $connection = SemanticConnector::getConnection('pp_server');
      $connection->setUrl($form_state->getValue('url'));
      $connection->setCredentials([
        'username' => $form_state->getValue('username'),
        'password' => $form_state->getValue('password'),
      ]);

      $projects = $connection->getApi('PPX')->getProjects();
      foreach ($projects as $project) {
        $project_options[$project->uuid] = $project->label;
      }
    }

    $projects_element['#options'] = $project_options;
    return $projects_element;
  }
}
