<?php

class GreenRope_CartItem
{
	private $itemID;
	private $itemQuantity;
	private $itemSubtotal;

	public function getItemID()
	{
		return $this->itemID;
	}

	public function setItemID($new_itemID)
	{
		$this->itemID = $new_itemID;
	}

	public function getItemQuantity()
	{
		return $this->itemQuantity;
	}

	public function setItemQuantity($new_itemQuantity)
	{
		$this->itemQuantity = $new_itemQuantity;
	}

	public function getItemSubtotal()
	{
		return $this->itemSubtotal;
	}

	public function setItemSubtotal($new_itemSubtotal)
	{
		$this->itemSubtotal = $new_itemSubtotal;
	}
}

class GreenRope_OrderExport_Model_Export
{

/**
* Generates an XML file from the order data and places it into
* the var/export directory
*
* @param Mage_Sales_Model_Order $order order object
*
* @return boolean
*/
    public function exportOrder($order)
    {
        $dirPath = Mage::getBaseDir('var') . DS . 'export';
		$filePath = $dirPath. DS . $order->getIncrementId() . '.txt';

		// Initial connection to GreenRope
		$greenrope_url = "https://api.stgi.net/xml.pl";
		$API_UserName = urlencode(Mage::getStoreConfig('greenrope/settings/GreenRopeUsername'));
		$API_Password = urlencode(Mage::getStoreConfig('greenrope/settings/GreenRopePassword'));
		$GreenRopeGroup = Mage::getStoreConfig('greenrope/settings/GreenRopeGroup');

		$API_XML = "<GetAuthTokenRequest>\n";
		$API_XML .= "</GetAuthTokenRequest>\n";

		$API_XML = urlencode($API_XML);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $greenrope_url);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);

		// turning off the server and peer verification(TrustManager Concept).
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);

		// NVPRequest for submitting to server
		$nvpreq = "email=$API_UserName&password=$API_Password&xml=$API_XML";

		// setting the nvpreq as POST FIELD to curl
		curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

		// getting response from server
		$httpResponse = curl_exec($ch);

		$my_reader = new XMLReader();
		$my_reader->xml($httpResponse);

		$xml_result = "";
		$xml_token = "";
		$xml_errorcode = "";
		$xml_errormsg = "";

		while ($my_reader->read())
		{
			switch ($my_reader->nodeType)
			{
				case XMLReader::ELEMENT:
					if ($my_reader->name == "Result")
					{
						$my_reader->read();
						$xml_result = $my_reader->value;
					}
					else if ($my_reader->name == "Token")
					{
						$my_reader->read();
						$xml_token = $my_reader->value;
					}
					else if ($my_reader->name == "ErrorCode")
					{
						$my_reader->read();
						$xml_errorcode = $my_reader->value;
					}
					else if ($my_reader->name == "ErrorText")
					{
						$my_reader->read();
						$xml_errormsg = $my_reader->value;
					}

					break;
			}
		}

		if ($xml_result == "Error")
		{
			Mage::log(
					"Error - error code was $xml_errorcode, error message was $xml_errormsg\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);
		}
		else if ($xml_result == "Success")
		{
			$API_Token = urlencode($xml_token);

			// Get the group ID
			$API_XML_String = "<GetGroupsRequest group_name=\"" . $GreenRopeGroup . "\">\n";
			$API_XML_String .= "</GetGroupsRequest>\n";

			$API_XML = urlencode($API_XML_String);

			$API_XML = urlencode($API_XML_String);

			// NVPRequest for submitting to server
			$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

			$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

			Mage::log(
					"Response for GetGroupsRequest was $httpResponse\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);

			$GetGroupsResponse = new SimpleXMLElement($httpResponse);

			$response_result = $GetGroupsResponse->Result;

			Mage::log(
					"Result for GetGroupsResponse was $response_result\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);

			if ($response_result != 'Success')
			{
				Mage::log(
						"Error - could not find group\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				return true;
			}

			$GreenRopeGroupID = $GetGroupsResponse->Groups->Group[0]->Group_id;

			$GreenRopeCartItems = array();

			foreach ($order->getAllItems() as $item)
			{
				// if (!isset($sku_counter[$item->getSku()])) {
				// $sku_counter[$item->getSku()] = 0;

				$item_summary_string = '';

				$item_summary_string .= 'Item ID: ' . $item->getProductId() . "\n";
				$item_summary_string .= 'Item SKU: ' . $item->getSku()  . "\n";
				$item_summary_string .= 'Item Name: ' . $item->getName()  . "\n";

				$my_product = Mage::getModel('catalog/product')->load($item->getProductId());

				$my_categories = $my_product->getCategoryIds();
				$product_category = '';
				$product_category_id = '';

				foreach ($my_categories as $my_cat_id)
				{
					$my_category = Mage::getModel('catalog/category')->load($my_cat_id);
					$item_summary_string .= 'Item Category: ' . $my_category->getName() . "\n";

					if ($my_category->getName() != '')
					{
						$product_category = $my_category->getName();
						break;
					}
				}

				$category_id = '';

				if (!(empty($product_category)))
				{
					// See if this category exists
					$API_XML_String = "<GetStoreItemCategoriesRequest group_name=\"" . $GreenRopeGroup . "\" category_name=\"" . $product_category . "\" >\n";
					$API_XML_String .= "</GetStoreItemCategoriesRequest>\n";

					$API_XML = urlencode($API_XML_String);

					// NVPRequest for submitting to server
					$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

					$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

					Mage::log(
							"Response for GetStoreItemCategories was $httpResponse\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					$GetStoreItemCategoriesResponse = new SimpleXMLElement($httpResponse);

					$response_result = $GetStoreItemCategoriesResponse->Result;

					Mage::log(
							"Result for GetStoreItemCategories was $response_result\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					if ($response_result != 'Success')
					{
						$response_error_code = $GetStoreItemCategoriesResponse->ErrorCode;
						$response_error_text = $GetStoreItemCategoriesResponse->ErrorText;

						Mage::log(
								"Error was $response_error_code: $response_error_text\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);

						if ($response_error_code == 5001)
						{
							// Add the category
							$API_XML_String = "<AddStoreItemCategoriesRequest>\n";
							$API_XML_String .= "<StoreItemCategories>\n";
							$API_XML_String .= "<StoreItemCategory>\n";
							$API_XML_String .= "<Group_id>" . $GreenRopeGroupID . "</Group_id>\n";
							$API_XML_String .= "<Category_name>" . $product_category . "</Category_name>\n";
							$API_XML_String .= "</StoreItemCategory>\n";
							$API_XML_String .= "</StoreItemCategories>\n";
							$API_XML_String .= "</AddStoreItemCategoriesRequest>\n";

							$API_XML = urlencode($API_XML_String);

							// NVPRequest for submitting to server
							$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

							$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

							Mage::log(
									"Response for AddStoreItemCategories was $httpResponse\n",
									Zend_Log::DEBUG,
									'order_export.log',
									true
								);

							$AddStoreItemCategoriesResponse = new SimpleXMLElement($httpResponse);
							$response_result = $AddStoreItemCategoriesResponse->StoreItemCategories->StoreItemCategory[0]->Result;

							if ($response_result <> 'Success')
							{
								Mage::log(
										"Add store item category failed\n",
										Zend_Log::DEBUG,
										'order_export.log',
										true
									);

								return true;
							}

							$category_id = $AddStoreItemCategoriesResponse->StoreItemCategories->StoreItemCategory[0]->Category_id;

							Mage::log(
									"Added store item category, ID was $category_id\n",
									Zend_Log::DEBUG,
									'order_export.log',
									true
								);
						}
						else
						{
							Mage::log(
									"Unknown error $response_error_code from GetStoreItemCategories\n",
									Zend_Log::DEBUG,
									'order_export.log',
									true
								);

							return true;
						}
					}
					else
					{
						$category_id = $GetStoreItemCategoriesResponse->StoreItemCategories->StoreItemCategory[0]->Category_id;

						Mage::log(
								"GetStoreItemCategory succeeded, category ID was $category_id\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
				}

				$item_summary_string .= 'Item Description: ' . $my_product->getDescription()  . "\n";
				$item_summary_string .= 'Item Price: ' . $item->getPrice()  . "\n";
				$item_summary_string .= 'Item Quantity: ' . $item->getQtyOrdered()  . "\n";
				$item_summary_string .= "\n\n";

				$item_description = $my_product->getDescription();
				$item_price = $item->getPrice();
				$item_qty = $item->getQtyOrdered();

				Mage::log(
					$item_summary_string,
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);

				// See if item exists
				$item_name = $item->getName();
				$API_XML_String = "<GetStoreItemsRequest item_name=\"$item_name\" group_id=\"$GreenRopeGroupID\">\n";
				$API_XML_String .= "</GetStoreItemsRequest>\n";
				$API_XML = urlencode($API_XML_String);

				// NVPRequest for submitting to server
				$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

				$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

				Mage::log(
						"Response for GetStoreItems was $httpResponse\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				$GetStoreItemsResponse = new SimpleXMLElement($httpResponse);

				$response_result = $GetStoreItemsResponse->Result;

				if ($response_result != 'Success')
				{
					$response_error_code = $GetStoreItemsResponse->ErrorCode;
					$response_error_text = $GetStoreItemsResponse->ErrorText;

					Mage::log(
							"Error was $response_error_code: $response_error_text\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					if ($response_error_code == 5301)
					{
						// Add the item
						$API_XML_String = "<AddStoreItemsRequest>\n";
						$API_XML_String .= "<StoreItems>\n";
						$API_XML_String .= "<StoreItem>\n";
						$API_XML_String .= "<Group_id>" . $GreenRopeGroupID . "</Group_id>\n";
						$API_XML_String .= "<Item_name>" . $item_name . "</Item_name>\n";
						$API_XML_String .= "<Item_description>" . $item_description . "</Item_description>\n";
						$API_XML_String .= "<Category_id>" . $category_id . "</Category_id>\n";
						$API_XML_String .= "<Item_price>" . $item_price . "</Item_price>\n";
						$API_XML_String .= "</StoreItem>\n";
						$API_XML_String .= "</StoreItems>\n";
						$API_XML_String .= "</AddStoreItemsRequest>\n";

						$API_XML = urlencode($API_XML_String);

						// NVPRequest for submitting to server
						$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

						$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

						Mage::log(
								"Response for AddStoreItems was $httpResponse\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);

						$AddStoreItemsResponse = new SimpleXMLElement($httpResponse);
						$response_result = $AddStoreItemsResponse->StoreItems->StoreItem[0]->Result;

						if ($response_result <> 'Success')
						{
							Mage::log(
									"Add store item failed\n",
									Zend_Log::DEBUG,
									'order_export.log',
									true
								);

							return true;
						}

						$item_id = $AddStoreItemsResponse->StoreItems->StoreItem[0]->Item_id;

						Mage::log(
								"Added store item, ID was $item_id\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
					else
					{
						Mage::log(
								"Unknown error $response_error_code from GetStoreItemCategories\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);

						return true;
					}
				}
				else
				{
					$item_id = $GetStoreItemsResponse->StoreItems->StoreItem[0]->Item_id;

					Mage::log(
							"GetStoreItem succeeded, item ID was $item_id\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					// Compare fields received to see if an update is needed
					$item_needs_update = false;

					if ($GetStoreItemsResponse->StoreItems->StoreItem[0]->Item_name != $item_name ||
					    $GetStoreItemsResponse->StoreItems->StoreItem[0]->Item_description != $item_description ||
						$GetStoreItemsResponse->StoreItems->StoreItem[0]->Item_price != $item_price)
					{
						$API_XML_String = "<EditStoreItemsRequest>\n";
						$API_XML_String .= "<StoreItems>\n";
						$API_XML_String .= "<StoreItem item_id=\"$item_id\">\n";
						$API_XML_String .= "<Item_name>$item_name</Item_name>\n";
						$API_XML_String .= "<Item_description>$item_description</Item_description>\n";
						$API_XML_String .= "<Item_price>$item_price</Item_price>\n";
						$API_XML_String .= "</StoreItem>\n";
						$API_XML_String .= "</StoreItems>\n";
						$API_XML_String .= "</EditStoreItemsRequest>\n";
						$API_XML = urlencode($API_XML_String);

						// NVPRequest for submitting to server
						$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

						$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

						Mage::log(
								"Response for EditStoreItems was $httpResponse\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);

						$EditStoreItemsResponse = new SimpleXMLElement($httpResponse);

						$response_result = $EditStoreItemsResponse->StoreItems->StoreItem[0]->Result;

						if ($response_result <> 'Success')
						{
							Mage::log(
									"Edit store item failed\n",
									Zend_Log::DEBUG,
									'order_export.log',
									true
								);

							return true;
						}
					}
				}

				// Save the item into the array for the cart calls later
				$GreenRopeCartItem = new GreenRope_CartItem();

				$GreenRopeCartItem->setItemID($item_id);
				$GreenRopeCartItem->setItemQuantity($item_qty);
				$GreenRopeCartItem->setItemSubtotal($item_qty * $item_price);

				$GreenRopeCartItems[] = $GreenRopeCartItem;
			}

			// Now customer
			if ($order->getCustomerId() === NULL)
			{
				$customer_address = $order->getBillingAddress();
				Mage::log(
						"No customer ID, got billing address from order\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				$customer_firstname =  $order->getCustomerFirstname();
				$customer_lastname = $order->getCustomerLastname();
				$customer_email = $order->getCustomerEmail();

				$customer_middlename = '';
				$customer_dob = '';
				$customer_gender = '';
			}
			else
			{
				Mage::log(
						"Customer ID found, customer ID was " . $order->getCustomerId() . "\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				$customer = Mage::getModel('customer/customer')->load($order->getCustomerId());

				$customer_id = $order->getCustomerId();
				$customer_email = $customer->getEmail();
				$customer_firstname = $customer->getFirstname();
				$customer_middlename = $customer->getMiddlename();
				$customer_lastname = $customer->getLastname();
				$customer_dob = $customer->getdob();
				$customer_gender = $customer->getGender();

				$customer_address = $customer->getDefaultBillingAddress();
			}

			$customer_company = $customer_address->getCompany();

			$street = $customer_address->getStreet();
			$customer_street = $street[0];
			$customer_city = $customer_address->getCity();
			$customer_state = $customer_address->getRegion();
			$customer_zip = $customer_address->getPostcode();
			$customer_country = $customer_address->getCountryId();
			$customer_telephone = $customer_address->getTelephone();
			$customer_fax = $customer_address->getFax();

			Mage::log(
					"Customer ID was $customer_id, customer email was $customer_email, customer first name = $customer_firstname, customer middle name was $customer_middlename, customer last name was $customer_lastname, customer DOB was $customer_dob, customer gender was $customer_gender, customer company was $customer_company, customer street address was $customer_street, customer city was $customer_city, customer state was $customer_state, customer zip was $customer_zip, customer country was $customer_country, customer telephone was $customer_telephone, customer fax was $customer_fax.\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);

			// Try adding the customer. If it already exists, update it.
			$API_XML_String = "<AddContactsRequest>\n";
			$API_XML_String .= "<Contacts>\n";
			$API_XML_String .= "<Contact>\n";
			$API_XML_String .= "<Firstname>$customer_firstname</Firstname>\n";
			$API_XML_String .= "<Lastname>$customer_lastname</Lastname>\n";
			$API_XML_String .= "<Address1>$customer_street</Address1>\n";
			$API_XML_String .= "<City>$customer_city</City>\n";
			$API_XML_String .= "<State>$customer_state</State>\n";
			$API_XML_String .= "<Zip>$customer_zip</Zip>\n";
			$API_XML_String .= "<Country>$customer_country</Country>\n";

			if (!(empty($customer_telephone)))
			{
				$API_XML_String .= "<Phone>$customer_telephone</Phone>\n";
			}

			if (!(empty($customer_fax)))
			{
				$API_XML_String .= "<Fax>$customer_fax</Fax>\n";
			}

			$API_XML_String .= "<Email>$customer_email</Email>\n";

			if (!(empty($customer_dob)))
			{
				$API_XML_String .= "<Birthdate>$customer_dob</Birthdate>\n";
			}

			if (!(empty($customer_gender)))
			{
				$API_XML_String .= "<Gender>$customer_gender</Gender>\n";
			}

			$API_XML_String .= "<Groups>\n";
			$API_XML_String .= "<Group>$GreenRopeGroup</Group>\n";
			$API_XML_String .= "</Groups>\n";
			$API_XML_String .= "</Contact>\n";
			$API_XML_String .= "</Contacts>\n";
			$API_XML_String .= "</AddContactsRequest>\n";

			$API_XML = urlencode($API_XML_String);

			// NVPRequest for submitting to server
			$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

			$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

			Mage::log(
					"Response for AddContacts was $httpResponse\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);

			$AddContactsResponse = new SimpleXMLElement($httpResponse);
			$response_result = $AddContactsResponse->Contacts->Contact[0]->Result;

			if ($response_result <> 'Success')
			{
				if (substr($AddContactsResponse->Contacts->Contact[0]->ErrorText, 0, 36) == 'This contact already exists with ID ')
				{
					$gr_contact_id = substr($AddContactsResponse->Contacts->Contact[0]->ErrorText, 36);

					// Try adding the group
					$API_XML_String = "<AddContactsToGroupRequest group_name=\"" . $GreenRopeGroup . "\">\n";
					$API_XML_String .= "<Contacts>\n";
					$API_XML_String .= "<Contact contact_id=\"$gr_contact_id\" />\n";
					$API_XML_String .= "</Contacts>\n";
					$API_XML_String .= "</AddContactsToGroupRequest>\n";

					$API_XML = urlencode($API_XML_String);

					// NVPRequest for submitting to server
					$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

					$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

					Mage::log(
							"Response for AddContactsToGroup was $httpResponse\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					$AddContactsToGroupResponse = new SimpleXMLElement($httpResponse);
					$response_result = $AddContactsToGroupResponse->Contacts->Contact[0]->Result;

					if ($response_result == 'Success')
					{
						Mage::log(
								"Successfully added contact to group $GreenRopeGroup\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
					else
					{
						$response_error = $AddContactsToGroupResponse->Contacts->Contact[0]->ErrorText;

						Mage::log(
								"Error adding contact to group $GreenRopeGroup, error was $response_error\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
				}
				else
				{
					Mage::log(
					"Unrecognized Error Response for AddContacts; error response was " . $AddContactsResponse->Contacts->Contact[0]->ErrorText . "\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
					);

					return true;
				}
			}
			else
			{
				$gr_contact_id = $AddContactsResponse->Contacts->Contact[0]->Contact_id;

				Mage::log(
				"Successfully added contact, new ID was $gr_contact_id\n",
				Zend_Log::DEBUG,
				'order_export.log',
				true
				);
			}

			// Now do the cart & transaction
			$orderID = $order->getIncrementId();

			// Cart first

			if (count($GreenRopeCartItems) > 0)
			{
				$currenttime = date("Y-m-d H:i:s", Mage::getModel('core/date')->timestamp(time()));

				$API_XML_String = "<AddStoreShoppingCartRequest>\n";
				$API_XML_String .= "<Group_id>$GreenRopeGroupID</Group_id>\n";
				$API_XML_String .= "<CreationDate>$currenttime</CreationDate>\n";
				$API_XML_String .= "<Items>\n";

				foreach ($GreenRopeCartItems as $GreenRopeItem)
				{
					$API_XML_String .= "<Item>\n";
					$API_XML_String .= "<Item_id>" . $GreenRopeItem->getItemID() . "</Item_id>\n";
					$API_XML_String .= "<Quantity>" . $GreenRopeItem->getItemQuantity() . "</Quantity>\n";
					$API_XML_String .= "<SubtotalAmount>" . $GreenRopeItem->getItemSubtotal() . "</SubtotalAmount>\n";
					$API_XML_String .= "</Item>\n";
				}

				$API_XML_String .= "</Items>\n";
				$API_XML_String .= "</AddStoreShoppingCartRequest>\n";

				Mage::log(
						"Request for AddStoreShoppingCart was $API_XML_String\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				$API_XML = urlencode($API_XML_String);
				// NVPRequest for submitting to server
				$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

				$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

				Mage::log(
						"Response for AddStoreShoppingCart was $httpResponse\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

				$AddStoreShoppingCartResponse = new SimpleXMLElement($httpResponse);
				$response_result = $AddStoreShoppingCartResponse->Result;

				if ($response_result == 'Success')
				{
					$GR_Cart_ID = $AddStoreShoppingCartResponse->Cart_id;

					Mage::log(
							"Successfully added cart, ID was $GR_Cart_ID\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					// Now add the purchase
					$API_XML_String = "<AddStorePurchaseRequest>\n";
					$API_XML_String .= "<Group_id>$GreenRopeGroupID</Group_id>\n";
					$API_XML_String .= "<Cart_id>$GR_Cart_ID</Cart_id>\n";
					$API_XML_String .= "<CreationDate>$currenttime</CreationDate>\n";
					$API_XML_String .= "<PurchaserEmail>$customer_email</PurchaserEmail>\n";
					$API_XML_String .= "<PurchaserIP>" . $_SERVER['REMOTE_ADDR'] . "</PurchaserIP>\n";
					$API_XML_String .= "<TransactionDate>" . $currenttime . "</TransactionDate>\n";
					$API_XML_String .= "<TransactionAmount>" . $order->getGrandTotal() .  "</TransactionAmount>\n";
					$API_XML_String .= "<Transaction_id>" . "Magento" . $orderID . "</Transaction_id>\n";
					$API_XML_String .= "</AddStorePurchaseRequest>\n";

					Mage::log(
						"Request for AddStorePurchase was $API_XML_String\n",
						Zend_Log::DEBUG,
						'order_export.log',
						true
					);

					$API_XML = urlencode($API_XML_String);

					// NVPRequest for submitting to server
					$nvpreq = "email=$API_UserName&auth_token=$API_Token&xml=$API_XML";

					$httpResponse = $this->Send_CE_XML_Request($nvpreq);		// getting response from server

					Mage::log(
							"Response for AddStoreShoppingCart was $httpResponse\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);

					$AddStorePurchaseResponse = new SimpleXMLElement($httpResponse);
					$response_result = $AddStorePurchaseResponse->Result;

					if ($response_result == 'Success')
					{

						Mage::log(
								"Successfully added purchase\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
					else
					{
						$response_error = $AddStorePurchaseResponse->ErrorText;

						Mage::log(
								"Error adding store purchase, error was $response_error\n",
								Zend_Log::DEBUG,
								'order_export.log',
								true
							);
					}
				}
				else
				{
					$response_error = $AddStoreShoppingCartResponse->ErrorText;

					Mage::log(
							"Error adding shopping cart, error was $response_error\n",
							Zend_Log::DEBUG,
							'order_export.log',
							true
						);
				}
			}
			else
			{
				Mage::log(
				"No items found in cart\n",
				Zend_Log::DEBUG,
				'order_export.log',
				true
				);
			}
		}
		else
		{
			Mage::log(
					"Unrecognized XML\n",
					Zend_Log::DEBUG,
					'order_export.log',
					true
				);
		}

		return true;
    }

	private function Send_CE_XML_Request($request)
	{
		$gr_url = "https://api.stgi.net/xml.pl";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $gr_url);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);

		// turning off the server and peer verification(TrustManager Concept).
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

		return curl_exec($ch);
	}
}
