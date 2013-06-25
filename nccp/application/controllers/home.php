<?php 
// The main controller for the homepage.  

if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Home extends CI_Controller {

	// This is the default function which is run by the controller
	public function index () {

		$this->load->library( 'MobileTools' );

		// Check if this is mobile or not
		if ( $this->mobiletools->detect_mobile() )
			$data['mobile'] = true;
		else
			$data['mobile'] = false;

		$this->load->view( 'data_index', $data );

	}

}
