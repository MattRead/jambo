<?php

namespace Habari;

/**
 * Jambo a contact form plugin for Habari
 *
 * @package jambo
 *
 * @todo document the functions.
 * @todo use AJAX to submit form, fallback on default if no AJAX.
 * @todo allow "custom fields" to be added by user.
 * @todo redo the hook and make it easy to add other formui comment stuff.
 * @todo use Habari's spam filtering.
 */

class Jambo extends Plugin
{
	/**
	 * Create the default options for Jambo.
	 */
	private static function default_options()
	{
		$options = array(
			'jambo__send_to' => $_SERVER['SERVER_ADMIN'],
			'jambo__subject' => _t('[CONTACT FORM] %s', 'jambo'),
			'jambo__show_form_on_success' => 1,
			'jambo__success_msg' => _t('Thank you for your feedback. I\'ll get back to you as soon as possible.', 'jambo'),
			);
		return Plugins::filter('jambo_default_options', $options);
	}

	/**
	 * On activation, check and set default options
	 */
	public function action_plugin_activation( $file )
	{
		foreach ( self::default_options() as $name => $value ) {
			Options::set($name, $value);
		}
	}

	/**
	 * Build the configuration settings
	 */
	public function configure()
	{
		$ui = new FormUI( 'jambo_config' );

		// Add a text control for the address you want the email sent to
		$send_to = $ui->append( 'text', 'send_to', 'option:jambo__send_to', _t('Where To Send Email: ', 'jambo') );
		$send_to->add_validator( 'validate_required' );

		// Add a text control for email subject
		$subject = $ui->append( 'text', 'subject', 'option:jambo__subject', _t('Subject: ', 'jambo') );
		$subject->add_validator( 'validate_required' );

		// Add an explanation for the subject field. Shouldn't FormUI have an easier way to do this?
		$ui->append( 'static', 'subject_explanation', '<p>' . _t('An %s in the subject will be replaced with a subject provided by the user. If omitted, no subject will be requested.', 'jambo') . '</p>' );

		// Add a text control for the prefix to the success message
		$success_msg = $ui->append( 'textarea', 'success_msg', 'option:jambo__success_msg', _t('Success Message: ', 'jambo') );

		$ui->append( 'submit', 'save', _t('Save', 'jambo') );
		return $ui;
	}

	/**
	 * Find out if we should request a subject
	 **/
	private static function ask_subject( $subject = null )
	{
		if( !$subject ) {
			$subject = Options::get( 'jambo__subject' );
		}

		if( strpos($subject, '%s') === false ) {
			$ask = false;
		} else {
			$ask = true;
		}
		return Plugins::filter( 'jambo_ask_subject', $ask );
	}

	/**
	 * Implement the shortcode to show the form
	 */
	function filter_shortcode_contact_form( $content, $code, Array $attrs, $context)
	{
		return $this->build_jambo_form( $attrs, $context )->get();
	}

	/**
	 * Get the jambo form
	 */
	private function build_jambo_form( Array $attrs, $context = null )
	{
		// borrow default values from the comment forms
		$commenter_name = '';
		$commenter_email = '';
		$commenter_url = '';
		$commenter_content = '';
		$user = User::identify();
		if ( isset( $_SESSION['comment'] ) ) {
			$details = Session::get_set( 'comment' );
			$commenter_name = $details['name'];
			$commenter_email = $details['email'];
			$commenter_url = $details['url'];
			$commenter_content = $details['content'];
		}
		elseif ( $user->loggedin ) {
			$commenter_name = $user->displayname;
			$commenter_email = $user->email;
		}

		// Process settings from shortcode and database
		$settings = array(
			'subject' => Options::get('jambo__subject'),
			'send_to' => Options::get('jambo__send_to'),
			'success_message' => Options::get('jambo__success_msg')
		);
		$settings = array_merge( $settings, $attrs );

		// Now start the form.
		$form = new FormUI( 'jambo' );

		// Create the Name field
		$form->append('text', 'jambo_name', 'null:null', _t('Name', 'jambo'), 'formcontrol_text')
			->add_validator('validate_required', _t('Your Name is required.', 'jambo'))
			->id = 'jambo_name';
		$form->jambo_name->tabindex = 1;
		$form->jambo_name->value = $commenter_name;

		// Create the Email field
		$form->append('text', 'jambo_email', 'null:null', _t('Email', 'jambo'), 'formcontrol_text')
			->add_validator( 'validate_email', _t( 'Your Email must be a valid address.' ) )
			->id = 'jambo_email';
		$form->jambo_email->tabindex = 2;
		$form->jambo_email->caption = _t( 'Email' );
		$form->jambo_email->value = $commenter_email;

		// Create the Subject field, if requested
		if( self::ask_subject( $settings['subject'] ) ) {
			$form->append('text', 'jambo_subject', 'null:null', _t('Subject', 'jambo'), 'formcontrol_text')
				->id = 'jambo_subject';
			$form->jambo_subject->tabindex = 3;
		}

		// Create the Message field
		$form->append('text', 'jambo_message', 'null:null', _t('Message', 'jambo'), 'formcontrol_textarea')
			->add_validator('validate_required', _t('Your message cannot be blank.', 'jambo'))
			->id = 'jambo_message';
		$form->jambo_message->tabindex = 4;

		// Create the Submit button
		$form->append( 'submit', 'jambo_submit', _t('Submit', 'jambo'), 'formcontrol_submit' );
		$form->jambo_submit->tabindex = 5;

		// Create hidden token fields
		self::insert_token($form);

		// Set up form processing
		$form->on_success(array($this, 'process_jambo_form'), $settings);

		// Allow modification of form
		Plugins::act('jambo_form', $form, $this);

		// Return the form object
		return $form;
	}

	/**
	 * Process the jambo form and send the email
	 */
	public function process_jambo_form( FormUI $form, Array $settings )
	{
		// get the values and the stored options.
		$email = array();
		$email['sent'] = false;
		$email['name'] = $form->jambo_name->value;
		$email['send_to'] =	 $settings['send_to'];
		$email['email'] = $form->jambo_email->value;
		$email['message'] = $form->jambo_message->value;
		$email['success_message'] = $settings['success_message'];
		$email['valid'] = true;

		// Develop the email subject
		$email['subject'] = $settings['subject'];
		if ( self::ask_subject($email['subject']) ) {
			$email['subject'] = sprintf($email['subject'], $form->jambo_subject->value);
		}

		// Utils::mail expects an array
		$email['headers'] = array(
			'MIME-Version' => '1.0',
			'From' => "{$email['name']} <{$email['email']}>",
			'Content-Type' => 'text/plain; charset="utf-8"'
			);
		$email = Plugins::filter( 'jambo_email', $email, $form ); // Allow another plugin to modify the sent email

		if ( $email['valid'] ) {
			$email['sent'] = Utils::mail( $email['send_to'], $email['subject'], $email['message'], $email['headers'] );
		}

		return '<p class="jambo-confirmation" id="jambo">' . $email['success_message']  .'</p>';
	}

	/**
	 * Check the email using spam filter
	 */
	public function filter_jambo_email( Array $email, FormUI $form )
	{
		if ( !self::verify_token($form->token->value, $form->token_time->value) ) {
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die(
				'<h1>' . _t('The selected action is forbidden.', 'jambo') . '</h1>' .
				'<p>' . _t('You are submitting the form too fast and look like a spam bot.', 'jambo') . '</p>'
			);
		}

		// FIXME implement a blacklist or something.
		return $email;
	}

	/**
	 * Create the token based on the time string submitted and the UID for this Habari installation.
	 */
	private static function create_token( $timestamp )
	{
		$token = substr( md5( $timestamp . Options::get( 'GUID' ) ), 0, 10 );
		$token = Plugins::filter( 'jambo_token', $token, $timestamp );
		return $token;
	}

	/**
	 * Verify that the token and time passed are valid.
	 */
	private static function verify_token( $token, $timestamp )
	{
		if ( $token == self::create_token( $timestamp ) ) {
			$time = time();
			if ( $time > ($timestamp + 3) && $time < ($timestamp + 5*60) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add the token fields to the form.
	 */
	private static function insert_token( $form )
	{
		$timestamp = time();
		$token = self::create_token( $timestamp );
		$form->append( 'hidden', 'token', 'null:null' )->value = $token;
		$form->append( 'hidden', 'token_time', 'null:null' )->value = $timestamp;
		return $form;
	}
}

?>