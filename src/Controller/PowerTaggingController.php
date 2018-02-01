<?php
/**
 * @file
 * Contains \Drupal\powertagging\Controller\PowerTaggingController class.
 */

namespace Drupal\powertagging\Controller;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\powertagging\Entity\PowerTaggingConfig;
use Drupal\powertagging\PowerTagging;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for the PoolParty PowerTagging module.
 */
class PowerTaggingController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Returns concepts for the tag autocompletion.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object containing the search string.
   * @param PowerTaggingConfig $powertagging_config
   *   The PowerTagging configuration.
   * @param string $langcode
   *   The language of the entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse A JSON response containing the autocomplete suggestions.
   *   A JSON response containing the autocomplete suggestions.
   */
  public function autocompleteTags(Request $request, PowerTaggingConfig $powertagging_config, $langcode) {
    $terms = [];
    if ($string = $request->query->get('term')) {
      $powertagging = new PowerTagging($powertagging_config);
      $powertagging->suggest($string, $langcode);
      $suggested_concepts = $powertagging->getResult();
      foreach ($suggested_concepts as $concept) {
        $terms[] = array(
          'tid' => $concept['tid'],
          'uri' => $concept['uri'],
          'name' => $concept['prefLabel'],
          'value' => $concept['prefLabel'],
          'type' => 'concept',
        );
      }
    }
    usort($terms, [$this, 'sortAutocompleteTags']);

    return new JsonResponse($terms);
  }

  /**
   * Function for extracting concepts and free terms from the content.
   *
   * @param PowerTaggingConfig $powertagging_config
   */
  public function extract($powertagging_config) {
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    $files = isset($_POST['files']) ? $_POST['files'] : [];
    $settings = $_POST['settings'];

    // Remove line breaks and HTML tags from the content and convert HTML
    // characters to normal ones.
    $content = html_entity_decode(str_replace(array("\r", "\n", "\t"), "", strip_tags($content)), ENT_COMPAT, 'UTF-8');

    $powertagging = new PowerTagging($powertagging_config);
    $powertagging->extract($content, $files, $settings);
    echo Json::encode($powertagging->getResult());
    exit();
  }

  protected function sortAutocompleteTags($a, $b) {
    return strcasecmp($a['name'], $b['name']);
  }

}