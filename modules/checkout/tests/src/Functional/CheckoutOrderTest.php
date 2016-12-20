<?php

namespace Drupal\Tests\commerce_checkout\Functional;

use Drupal\commerce_price\Price;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItemType;

/**
 * Tests the checkout of an order.
 *
 * @group commerce
 */
class CheckoutOrderTest extends CommerceBrowserTestBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['system', 'field', 'user', 'text',
    'entity', 'views', 'address', 'profile', 'commerce', 'inline_entity_form',
    'commerce_price', 'commerce_product', 'commerce_cart',
    'commerce_checkout', 'commerce_order', 'views_ui',
    // @see https://www.drupal.org/node/2807567
    'editor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_checkout_flow',
      'administer views',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->placeBlock('commerce_cart');

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => 9.99,
        'currency_code' => 'USD',
      ],
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$variation],
      'stores' => [$this->store],
    ]);
  }

  /**
   * Tests order access.
   *
   * @group access
   */
  public function testOrderAccess() {
    $checkout_access_role = $this->createRole(['access checkout']);
    $user = $this->drupalCreateUser();
    $user->addRole($checkout_access_role);
    $user->save();
    $user2 = $this->drupalCreateUser();
    $user2->addRole($checkout_access_role);
    $user2->save();

    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country' => 'FR',
        'postal_code' => '75002',
        'locality' => 'Paris',
        'address_line1' => 'A french street',
        'given_name' => 'John',
        'family_name' => 'LeSmith',
      ],
    ]);
    $profile->save();
    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => 2,
      'unit_price' => new Price('12.00', 'USD'),
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store,
      'state' => 'in_checkout',
      'order_number' => '6',
      'mail' => 'test@example.com',
      'uid' => $user->id(),
      'ip_address' => '127.0.0.1',
      'billing_profile' => $profile,
      'order_items' => [$order_item],
      'cart' => TRUE,
    ]);
    $order->save();

    $checkout_url = '/checkout/' . $order->id();
    $order_information_url = '/checkout/' . $order->id() . '/order_information';
    $review_url = '/checkout/' . $order->id() . '/review';
    $complete_url = '/checkout/' . $order->id() . '/complete';

    // Anonymous user with no session.
    $this->drupalLogout();
    $this->drupalGet($checkout_url);
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated order owner (re-login.)
    $this->drupalLogin($user);
    $this->drupalGet($checkout_url);
    $this->assertSession()->statusCodeEquals(200);

    // Authenticated user who does not own the order.
    $this->drupalLogin($user2);
    $this->drupalGet($checkout_url);
    $this->assertSession()->statusCodeEquals(403);

    // Trying to access the checkout completion page with an authenticated
    // user not owning the order.
    $this->drupalGet($complete_url);
    $this->assertSession()->statusCodeEquals(403);

    // Cart owner trying to access the checkout completion page should
    // redirect.
    $this->drupalLogin($user);
    $this->drupalGet($complete_url);
    $this->assertSession()->addressNotEquals($complete_url);

    // Review page with order owner.
    $this->drupalGet($review_url);
    $this->assertSession()->addressNotEquals($review_url);

    // Review page with non-owner.
    $this->drupalLogin($user2);
    $this->drupalGet($review_url);
    $this->assertSession()->statusCodeEquals(403);

    // Order with no order items.
    $this->drupalLogin($user);
    $order->removeItem($order_item)->save();
    $this->drupalGet($checkout_url);
    $this->assertSession()->statusCodeEquals(403);

    // Go to review checkout step.
    $order->addItem($order_item)->save();
    $order->checkout_step = 'review';
    $order->save();

    // Try accessing the review step.
    $this->drupalGet($review_url);
    $this->assertSession()->addressEquals($review_url);
    $this->assertSession()->statusCodeEquals(200);

    // Try accessing the previous step that has no previous_label.
    $this->drupalGet($order_information_url);
    // We get redirected to the review step.
    $this->assertSession()->addressEquals($review_url);
    $this->assertSession()->statusCodeEquals(200);

    // Complete checkout.
    $order->checkout_step = 'complete';
    $transition = $order->getState()->getWorkflow()->getTransition('place');
    $order->getState()->applyTransition($transition);
    $order->save();

    // Try accessing the first step.
    $this->drupalGet($order_information_url);
    $this->assertSession()->addressEquals($complete_url);
    $this->assertSession()->statusCodeEquals(200);

    // Try accessing the review step.
    $this->drupalGet($review_url);
    $this->assertSession()->addressEquals($complete_url);
    $this->assertSession()->statusCodeEquals(200);

    // Try accessing the complete step.
    $this->drupalGet($complete_url);
    $this->assertSession()->addressEquals($complete_url);
    $this->assertSession()->statusCodeEquals(200);

    // Cancel the order.
    // $order->state = 'canceled';
    // $order->save();
    // $this->drupalGet($complete_url);
    // $this->assertSession()->addressEquals($complete_url);
    // $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests than an order can go through checkout steps.
   */
  public function testGuestOrderCheckout() {
    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $this->assertSession()->pageTextContains('1 item');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextNotContains('Order Summary');
    $this->submitForm([], 'Continue as Guest');
    $this->submitForm([
      'contact_information[email]' => 'guest@example.com',
      'contact_information[email_confirm]' => 'guest@example.com',
      'billing_information[profile][address][0][address][given_name]' => $this->randomString(),
      'billing_information[profile][address][0][address][family_name]' => $this->randomString(),
      'billing_information[profile][address][0][address][organization]' => $this->randomString(),
      'billing_information[profile][address][0][address][address_line1]' => $this->randomString(),
      'billing_information[profile][address][0][address][postal_code]' => '94043',
      'billing_information[profile][address][0][address][locality]' => 'Mountain View',
      'billing_information[profile][address][0][address][administrative_area]' => 'CA',
    ], 'Continue to review');
    $this->assertSession()->pageTextContains('Contact information');
    $this->assertSession()->pageTextContains('Billing information');
    $this->assertSession()->pageTextContains('Order Summary');
    $this->submitForm([], 'Pay and complete purchase');
    $this->assertSession()->pageTextContains('Your order number is 1. You can view your order on your account page when logged in.');
    $this->assertSession()->pageTextContains('0 items');
    // Test second order.
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $this->assertSession()->pageTextContains('1 item');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextNotContains('Order Summary');
    $this->submitForm([], 'Continue as Guest');
    $this->submitForm([
      'contact_information[email]' => 'guest@example.com',
      'contact_information[email_confirm]' => 'guest@example.com',
      'billing_information[profile][address][0][address][given_name]' => $this->randomString(),
      'billing_information[profile][address][0][address][family_name]' => $this->randomString(),
      'billing_information[profile][address][0][address][organization]' => $this->randomString(),
      'billing_information[profile][address][0][address][address_line1]' => $this->randomString(),
      'billing_information[profile][address][0][address][postal_code]' => '94043',
      'billing_information[profile][address][0][address][locality]' => 'Mountain View',
      'billing_information[profile][address][0][address][administrative_area]' => 'CA',
    ], 'Continue to review');
    $this->assertSession()->pageTextContains('Contact information');
    $this->assertSession()->pageTextContains('Billing information');
    $this->assertSession()->pageTextContains('Order Summary');
    $this->submitForm([], 'Pay and complete purchase');
    $this->assertSession()->pageTextContains('Your order number is 2. You can view your order on your account page when logged in.');
    $this->assertSession()->pageTextContains('0 items');
  }

  /**
   * Tests that you can register from the checkout pane.
   */
  public function testRegisterOrderCheckout() {
    $config = \Drupal::configFactory()->getEditable('commerce_checkout.commerce_checkout_flow.default');
    $config->set('configuration.panes.login.allow_guest_checkout', FALSE);
    $config->set('configuration.panes.login.allow_registration', TRUE);
    $config->save();

    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextContains('New Customer');
    $this->submitForm([
      'login[register][name]' => 'User name',
      'login[register][mail]' => 'guest@example.com',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Billing information');

    // Test account validation.
    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextContains('New Customer');

    $this->submitForm([
      'login[register][name]' => 'User name',
      'login[register][mail]' => '',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Email field is required.');

    $this->submitForm([
      'login[register][name]' => '',
      'login[register][mail]' => 'guest@example.com',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Username field is required.');

    $this->submitForm([
      'login[register][name]' => 'User name',
      'login[register][mail]' => 'guest@example.com',
      'login[register][password][pass1]' => '',
      'login[register][password][pass2]' => '',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Password field is required.');

    $this->submitForm([
      'login[register][name]' => 'User name double email',
      'login[register][mail]' => 'guest@example.com',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('The email address guest@example.com is already taken.');

    $this->submitForm([
      'login[register][name]' => 'User @#.``^ ù % name invalid',
      'login[register][mail]' => 'guest2@example.com',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('The username contains an illegal character.');

    $this->submitForm([
      'login[register][name]' => 'User name',
      'login[register][mail]' => 'guest2@example.com',
      'login[register][password][pass1]' => 'pass',
      'login[register][password][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('The username User name is already taken.');
  }

  /**
   * Tests the order summary.
   */
  public function testOrderSummary() {
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');

    // Test the default settings: ensure the default view is shown.
    $this->drupalGet('/checkout/1');
    $this->assertSession()->elementExists('css', '.view-id-commerce_checkout_order_summary');

    // Disable the order summary.
    $this->drupalGet('/admin/commerce/config/checkout-flows/manage/default');
    $this->submitForm(['configuration[order_summary_view]' => ''], t('Save'));
    $this->drupalGet('/checkout/1');
    $this->assertSession()->elementNotExists('css', '.view-id-commerce_checkout_order_summary');

    // Use a different view for the order summary.
    $this->drupalGet('/admin/structure/views/view/commerce_checkout_order_summary/duplicate');
    $this->submitForm(['id' => 'duplicate_of_commerce_checkout_order_summary'], 'Duplicate');
    $this->drupalGet('/admin/commerce/config/checkout-flows/manage/default');
    $this->submitForm(['configuration[order_summary_view]' => 'duplicate_of_commerce_checkout_order_summary'], t('Save'));
    $this->drupalGet('/checkout/1');
    $this->assertSession()->elementExists('css', '.view-id-duplicate_of_commerce_checkout_order_summary');
  }

}
