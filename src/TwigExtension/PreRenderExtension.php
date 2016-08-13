<?php

namespace Drupal\twig_pre_render\TwigExtension;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerResolverInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\TwigExtension;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Utility\ThemeRegistry;

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
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $element_info
   *   The element info manager.
   */
  public function __construct(RendererInterface $renderer, ControllerResolverInterface $controller_resolver, Registry $theme_registry, ElementInfoManagerInterface $element_info) {
    parent::__construct($renderer);
    $this->controllerResolver = $controller_resolver;
    /** @var ThemeRegistry $themeRegistry */
    $this->themeRegistry = $theme_registry->getRuntime();
    $this->elementInfo = $element_info;
  }

  /**
   * Generates a list of all Twig functions that this extension defines.
   *
   * @return array
   *   A key/value array that defines custom Twig functions. The key denotes the
   *   function name used in the tag, e.g.:
   *
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
   */
  protected function preRender(&$variables) {
    $controller_resolver = $this->controllerResolver;
    $theme_registry = $this->themeRegistry;

    // If the default values for this element have not been loaded yet, populate
    // them.
    $children = [];
    if (isset($variables['#sorted'])) {
      $children = Element::children($variables);
    }
    if (isset($variables['#type']) && empty($variables['#defaults_loaded'])) {
      $variables += $this->elementInfo->getInfo($variables['#type']);
    }

    // Runs each pre-render function.
    if (isset($variables['#pre_render'])) {
      foreach ($variables['#pre_render'] as $callable) {
        if (is_string($callable) && strpos($callable, '::') === FALSE) {
          $callable = $controller_resolver->getControllerFromDefinition($callable);
        }
        $variables = call_user_func($callable, $variables);
      }
    }
    if (isset($variables['#theme'])) {
      // Preps the variables to match those described in the theme hook.
      $theme_hook = $variables['#theme'];
      $info = $theme_registry->get($theme_hook);
      if (isset($info['variables'])) {
        foreach (array_keys($info['variables']) as $name) {
          if (isset($variables["#$name"]) || array_key_exists("#$name", $variables)) {
            $variables[$name] = $variables["#$name"];
          }
        }
      }
      else {
        $children = Element::children($variables);
        $variables[$info['render element']] = $variables;
        // Give a hint to render engines to prevent infinite recursion.
        $variables[$info['render element']]['#render_children'] = TRUE;
      }

      // Runs each preprocess function.
      if (isset($info['preprocess functions'])) {
        $elements = $variables;
        foreach ($info['preprocess functions'] as $delta => $preprocessor_function) {
          if (function_exists($preprocessor_function)) {
            // Discard the default preprocessor variables.
            if ($delta == 1) {
              $elements = $variables;
            }
            $preprocessor_function($variables, $theme_hook, $info);
            if ($delta + 1 == count($info['preprocess functions'])) {
              // Catch any new child elements that have been added.
              $children += array_keys(array_diff_key($variables, $elements));
            }
          }
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

    // Check access.
    if (!isset($variables['#access']) && isset($variables['#access_callback'])) {
      if (is_string($variables['#access_callback']) && strpos($variables['#access_callback'], '::') === FALSE) {
        $variables['#access_callback'] = $this->controllerResolver->getControllerFromDefinition($variables['#access_callback']);
      }
      $variables['#access'] = call_user_func($variables['#access_callback'], $variables);
    }

    // Return nothing if user does not have access.
    if (isset($variables['#access'])) {
      // If #access is an AccessResultInterface object, we must apply it's
      // cacheability metadata to the render array.
      if ($variables['#access'] instanceof AccessResultInterface) {
        $this->renderer->addCacheableDependency($variables, $variables['#access']);
        if (!$variables['#access']->isAllowed()) {
          return;
        }
      }
      elseif ($variables['#access'] === FALSE) {
        return;
      }
    }
    // Render any child elements.
    foreach ($children as $child) {
      if (is_array($variables[$child])
        && !empty($variables[$child])
        && substr($child, 0, 1) !== '#'
        && $child !== 'content'
        && $child !== 'items') {
        $this->preRender($variables[$child]);
        // Hack to make the vars more presentable for existing templates.
        // @TODO: Revisit the problem of matching output to all the hundreds of
        // different template preprocess actions, and also decide if it is even
        // an issue in the long run.
        if (!empty($variables['#theme']) && $variables['#theme'] == 'field') {
          $variables['items'][$child] = $variables[$child];
        }
        else {
          $variables['content'][$child] = $variables[$child];
        }
      }
    }
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
    // Avoid re-rendering any array.
    if (!empty($variables['directory'])) {
      return $variables;
    }
    $this->preRender($variables);
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
      $this->preRender($variables);
    }
    $attributes = [];
    foreach ($variables['#items'] as $key => $item) {
      $image = $variables[$key];
      if (!empty($image['responsive_image_style_id'])) {
        $image_sources = $image['responsive_image']['sources'];
        $image_tag = $image['responsive_image']['output_image_tag'];
        $image = $image['responsive_image']['img_element'];
        // We only need the sources tag if we're using the picture element.
        if (!$image_tag) {
          $image['sources'] = $image_sources;
        }
      }
      elseif (!empty($image['image_style'])) {
        $image = $image['image']['image'];
      }
      else {
        $image = !empty($image['image']) ? $image['image'] : [];
      }
      $image['src'] = isset($image['uri']) ? file_create_url($image['uri']) : NULL;
      unset($image['attributes']['src']);
      $attributes[] = $image;
    }
    if (count($attributes) == 1) {
      $attributes = $attributes[0];
    }
    return $attributes;
  }

}
