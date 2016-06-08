<?php
namespace Drupal\twig_pre_render\TwigExtension;

use Drupal\Core\Controller\ControllerResolverInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\TwigExtension;
use Drupal\Core\Theme\Registry;

/**
 * A Twig extension that adds a Drupal pre-render Twig function.
 */
class PreRenderExtension extends TwigExtension {

  /**
   * The Controller Resolver.
   *
   * @var \Drupal\Core\Controller\ControllerResolverInterface
   */
  protected $controllerResolver;

  /**
   * The Theme Registry.
   *
   * @var \Drupal\Core\Utility\ThemeRegistry
   */
  protected $themeRegistry;

  /**
   * The element info.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface
   */
  protected $elementInfo;

  /**
   * Constructs \Drupal\twig_pre_render\TwigExtension\ImageAttrExtension.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Controller\ControllerResolverInterface $controller_resolver
   *   The controller resolver.
   * @param \Drupal\Core\Theme\Registry $theme_registry
   *   The theme registry.
   */
  public function __construct(RendererInterface $renderer, ControllerResolverInterface $controller_resolver, Registry $theme_registry, ElementInfoManagerInterface $element_info) {
    parent::__construct($renderer);
    $this->controllerResolver = $controller_resolver;
    $this->themeRegistry = $theme_registry;
    $this->elementInfo = $element_info;
  }

  /**
   * Generates a list of all Twig functions that this extension defines.
   *
   * @return array
   *   A key/value array that defines custom Twig functions. The key denotes the
   *   function name used in the tag, e.g.:
   * @code
   *   {{ image_attr() }}
   * @endcode
   *
   *   The value is a standard PHP callback that defines what the function does.
   */
  public function getFunctions() {
    return [
      'pre_render' => new \Twig_SimpleFunction(
        'pre_render',
        [$this, 'preRenderFunction']
      ),
      'image_attr' => new \Twig_SimpleFunction(
        'image_attr',
        [$this, 'imageAttrFunction']
      ),
    ];
  }

  /**
   * Gets a unique identifier for this Twig extension.
   *
   * @return string
   *   A unique identifier for this Twig extension.
   */
  public function getName() {
    return 'twig_pre_render.pre_render_extension';
  }

  /**
   * Pre-renders and preprocesses a render array.
   *
   * @param array $variables
   *   The render array.
   *
   * @return mixed
   *   Returns empty if no access is allowed.
   */
  protected function preRender(&$variables) {
    // If the default values for this element have not been loaded yet, populate
    // them.
    if (isset($variables['#type']) && empty($variables['#defaults_loaded'])) {
      $variables += $this->elementInfo->getInfo($variables['#type']);
    }
    // Check basic access for the element.
    if (!isset($elements['#access']) && isset($elements['#access_callback'])) {
      if (is_string($elements['#access_callback']) && strpos($elements['#access_callback'], '::') === FALSE) {
        $elements['#access_callback'] = $this->controllerResolver->getControllerFromDefinition($elements['#access_callback']);
      }
      $elements['#access'] = call_user_func($elements['#access_callback'], $elements);
    }
    // Early-return nothing if user does not have access.
    if (empty($elements) || (isset($elements['#access']) && !$elements['#access'])) {
      return '';
    }
    // Pre-render and pre-process by the theme hook.
    if (isset($variables['#theme']) || isset($variables['#theme_wrappers'])) {
      $controller_resolver = $this->controllerResolver;
      $theme_registry = $this->themeRegistry;
      $info = $theme_registry->get($variables['#theme'])[$variables['#theme']];
      // Runs each pre-render function.
      if (isset($variables['#pre_render'])) {
        foreach ($variables['#pre_render'] as $callable) {
          if (is_string($callable) && strpos($callable, '::') === FALSE) {
            $callable = $controller_resolver->getControllerFromDefinition($callable);
          }
          $variables = call_user_func($callable, $variables);
        }
      }
      // Preps the variables to match those described in the theme hook.
      $element = $variables;
      if (isset($info['variables'])) {
        foreach (array_keys($info['variables']) as $name) {
          if (isset($element["#$name"]) || array_key_exists("#$name", $element)) {
            $variables[$name] = $element["#$name"];
          }
        }
      }
      else {
        $variables[$info['render element']] = $element;
        // Give a hint to render engines to prevent infinite recursion.
        $variables[$info['render element']]['#render_children'] = TRUE;
      }

      // Runs each preprocess function.
      if (isset($info['preprocess functions'])) {
        foreach ($info['preprocess functions'] as $preprocessor_function) {
          if (function_exists($preprocessor_function)) {
            $preprocessor_function($variables, $variables['#theme'], $info);
          }
        }
      }
      // Assign out default attributes.
      if (!isset($default_attributes)) {
        $default_attributes = new Attribute();
      }
      foreach ([
        'attributes',
        'title_attributes',
        'content_attributes',
      ] as $key) {
        if (isset($variables[$key]) && !($variables[$key] instanceof Attribute)) {
          if ($variables[$key]) {
            $variables[$key] = new Attribute($variables[$key]);
          }
          else {
            // Create empty attributes.
            $variables[$key] = clone $default_attributes;
          }
        }
      }
    }
  }

  /**
   * Returns an array of context variables from an input render array.
   *
   * @param array $variables
   *   The render array.
   *
   * @return array
   *   An array of context variables.
   */
  protected function preRenderRecursive($variables) {
    if (!empty($variables['#theme']) || !empty($variables['#type'])) {
      $this->preRender($variables);
      // Catch any render arrays that have been added via preprocess.
      foreach ($variables as $delta => $element) {
        // Some preprocess functions duplicate the render array.
        // It is logical to assume:
        // - No renderable entity has an immediate child of the same theme type.
        // - All children are only one level deep.
        // @TODO Revisit this assumption before site launch & on core upgrades.
        if (is_array($element) && ((!empty($element['#theme']) && $variables['#theme'] != $element['#theme']) || (!empty($element['#type'])))) {
          $variables[$delta] = $this->preRenderRecursive($element);
        }
      }
    }
    return $variables;
  }

  /**
   * Returns an array of fully pre-rendered and preprocessed components.
   *
   * @param array $variables
   *   The input render array.
   *
   * @return array
   *   An array of render array components.
   */
  public function preRenderFunction($variables) {
    $variables = $this->preRenderRecursive($variables);
    return $variables;
  }

  /**
   * Returns an array of image attributes from an input field.
   *
   * @param array $variables
   *   The field variables render array.
   *
   * @return array
   *   An array of image attributes.
   */
  public function imageAttrFunction($variables) {
    if (!isset($variables['#field_type']) || $variables['#field_type'] != 'image') {
      return $variables;
    }
    // Skip pre-rendering if already pre-rendered.
    if (empty($variables[0]['directory'])) {
      $variables = $this->preRenderRecursive($variables);
    }
    $attributes = [];
    foreach ($variables['#items'] as $key => $item) {
      $image = $variables[$key];
      if (!empty($image['responsive_image_style_id'])) {
        $image_sources = $image['responsive_image']['sources'];
        $image = $image['responsive_image']['img_element'];
        $image['sources'] = $image_sources;
      }
      elseif (!empty($image['image_style'])) {
        $image = $image['image']['image'];
      }
      else {
        $image = $image['image'];
      }
      $image['src'] = isset($image['uri']) ? file_create_url($image['uri']) : NULL;
      $attributes[] = $image;
    }
    if (count($attributes) == 1) {
      $attributes = $attributes[0];
    }
    return $attributes;
  }

}
