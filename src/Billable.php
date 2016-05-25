<?php
namespace Phalcon\Cashier;

use Phalcon\Di\FactoryDefault;
use Exception;
use Carbon\Carbon;
use InvalidArgumentException;
use Stripe\Token as StripeToken;
use Stripe\Charge as StripeCharge;
use Stripe\Refund as StripeRefund;
use Stripe\Invoice as StripeInvoice;
use Stripe\Customer as StripeCustomer;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Error\InvalidRequest as StripeErrorInvalidRequest;

trait Billable
{
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripeKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int   $amount
     * @param  array $options
     * @return \Stripe\Charge
     *
     * @throws \Stripe\Error\Card
     */
    public function charge(array $options = [])
    {
        return StripeCharge::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param  string $charge
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \Stripe\Error\Refund
     */
    public function refund($charge, array $options = [])
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Invoice the customer for the given amount.nfig
     *
     * @param  string $description
     * @param  int    $amount
     * @param  array  $options
     * @return bool
     *
     * @throws \Stripe\Error\Card
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        if (! $this->stripe_id) {
            throw new InvalidArgumentException('User is not a customer. See the createAsStripeCustomer method.');
        }

        $options = array_merge(
            [
            'customer' => $this->stripe_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
            ],
            $options
        );

        StripeInvoiceItem::create(
            $options,
            ['api_key' => $this->getStripeKey()]
        );

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param string $subscription
     * @param string $plan
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user is on trial.
     *
     * @param  string      $subscription
     * @param  string|null $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
        $subscription->stripe_plan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param  string      $subscription
     * @param  string|null $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
        $subscription->stripe_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param string $subscription
     */
    public function subscription($subscription = 'default')
    {
        $subscriptions  = $this->subscriptions();
        foreach ($subscriptions as $object) {
            if ($object->name === $subscription) {
                return $object;
            }
        }
        return null;
    }

    /**
     * Get all of the subscriptions for the user.
     */
    public function subscriptions()
    {
        $this->hasMany(
            'id',
            Subscription::class,
            'user_id',
            [
                'alias' => 'subscriptions',
                'params' => ['order' => 'id DESC']
            ]
        );
        return $this->getRelated('subscriptions');
    }
    public function initialize()
    {

        //$this->hasMany('id', Subscription::class, 'user_id', ['alias' => 'subscriptions']);
    }
    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return StripeInvoice|bool
     */
    public function invoice()
    {
        if ($this->stripe_id) {
            try {
                return StripeInvoice::create(['customer' => $this->stripe_id], $this->getStripeKey())->pay();
            } catch (StripeErrorInvalidRequest $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     */
    public function upcomingInvoice()
    {
        try {
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->stripe_id],
                ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        } catch (StripeErrorInvalidRequest $e) {
            //
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param string $id
     */
    public function findInvoice($id)
    {
        try {
            return new Invoice($this, StripeInvoice::retrieve($id, $this->getStripeKey()));
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param string $id
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param string $id
     * @param array  $data
     * @param string $storagePath
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param bool  $includePending
     * @param array $parameters
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];
        $parameters = array_merge(['limit' => 24], $parameters);
        $stripeInvoices = $this->asStripeCustomer()->invoices($parameters);
        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (! is_null($stripeInvoices)) {
            foreach ($stripeInvoices->data as $invoice) {
                if ($invoice->paid || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }
        return $invoices;
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param array $parameters
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param  string $token
     * @return void
     */
    public function updateCard($token)
    {
        $customer = $this->asStripeCustomer();

        $token = StripeToken::retrieve($token, ['api_key' => $this->getStripeKey()]);

        // If the given token already has the card as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same card to
        // the user's account each time we go through this particular method call.
        if ($token->card->id === $customer->default_source) {
            return;
        }

        $card = $customer->sources->create(['source' => $token]);

        $customer->default_source = $card->id;

        $customer->save();

        // Next, we will get the default source for this user so we can update the last
        // four digits and the card brand on this user record in the database, which
        // is convenient when displaying on the front-end when updating the cards.
        $source = $customer->default_source
            ? $customer->sources->retrieve($customer->default_source)
            : null;

        if ($source) {
            $this->card_brand = $source->brand;
            $this->card_last_four = $source->last4;
        }

        $this->save();
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param  array|string $plans
     * @param  string       $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->stripe_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null(
            $this->subscriptions->first(
                function ($key, $value) use ($plan) {
                    return $value->stripe_plan === $plan && $value->valid();
                }
            )
        );
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        return ! is_null($this->stripe_id);
    }

    /**
     * Create a Stripe customer for the given user.
     *
     * @param  string $token
     * @param  array  $options
     * @return StripeCustomer
     */
    public function createAsStripeCustomer($token, array $options = [])
    {
        $options = array_key_exists('email', $options)
            ? $options : array_merge($options, ['email' => $this->email]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = StripeCustomer::create(
            $options,
            $this->getStripeKey()
        );

        $this->stripe_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the user.
     *
     * @return \Stripe\Customer
     */
    public function asStripeCustomer()
    {
        return StripeCustomer::retrieve($this->stripe_id, $this->getStripeKey());
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getStripeKey()
    {
        if (static::$stripeKey) {
            return static::$stripeKey;
        }
        $di = FactoryDefault::getDefault();
        $stripe = $di->getConfig()->stripe;
        return $stripe->secretKey ?: getenv('STRIPE_SECRET');
    }

    /**
     * Set the Stripe API key.
     *
     * @param  string $key
     * @return void
     */
    public static function setStripeKey($key)
    {
        static::$stripeKey = $key;
    }

    /**
     * @link https://stripe.com/docs/api/php#create_card_token
     * @param $option
     * @return bool
     */
    public function createCardToken($option)
    {
        $object = StripToken::create($option, ['api_key' => $this->getStripeKey()]);
        if (is_object($object)) {
            $token = $object->__toArray(true);
            return $token['id'] ?: false;
        }
        return false;
    }
}
