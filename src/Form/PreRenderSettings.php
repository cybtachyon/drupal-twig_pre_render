<?php

namespace Drupal\twig_pre_render\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements the PreRenderSettings form controller.
 *
 * @see \Drupal\Core\Form\FormBase
 */
class PreRenderSettings extends ConfigFormBase {

  /**
   * Build the PreRenderSettings form.
   *
   * @param array $form
   *   Default form array structure.
   * @param FormStateInterface $form_state
   *   Object containing current form state.
   *
   * @return array
   *   The render array defining the elements of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = \Drupal::config('twig_pre_render.settings');
    $form['lazyload'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable LazyLoad for pre-rendered images.'),
      '#default_value' => $config->get('lazyload'),
      '#description' => $this->t('When enabled, Twig Pre-Render will move the image source data to the relevant data-src type fields.'),
    ];
    $form['placeholder_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provide a placeholder path for Lazyloaded pre-rendered images.'),
      '#default_value' => $config->get('placeholder_path'),
      '#description' => $this->t('When LazyLoad is enabled, Twig Pre-Render will use this image as a placeholder. Example: /themes/custom/mytheme/components/components/images/assets/placeholder.png'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Getter method for Form ID.
   *
   * @return string
   *   The unique ID of the form defined by this class.
   */
  public function getFormId() {
    return 'twig_pre_render_settings';
  }

  /**
   * Return the editable config names.
   *
   * @return array
   *   The config names.
   */
  protected function getEditableConfigNames() {
    return [
      'twig_pre_render.settings',
    ];
  }

  /**
   * Implements a form submit handler.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Config\Config $config */
    $config = \Drupal::service('config.factory')->getEditable('twig_pre_render.settings');
    $config->set('lazyload', $form_state->getValue('lazyload'));
    $config->set('placeholder_path', $form_state->getValue('placeholder_path'));
    $config->save();
  }

}
