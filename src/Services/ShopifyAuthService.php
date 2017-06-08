<?php

namespace CultureKings\ShopifyAuth\Services;

use CultureKings\ShopifyAuth\Models\ShopifyApp;
use CultureKings\ShopifyAuth\Models\ShopifyAppUsers;
use CultureKings\ShopifyAuth\Models\ShopifyScriptTag;
use CultureKings\ShopifyAuth\Models\ShopifyWebhooks;
use CultureKings\ShopifyAuth\Models\ShopifyUser;
use Illuminate;
use CultureKings\ShopifyAuth\ShopifyApi;

/**
 * Class ShopifyAuthService.
 */
class ShopifyAuthService
{
    protected $shopify;

    public function __construct(
        ShopifyApi $shopify
    ) {
        $this->shopify = $shopify;
    }

    public function getAccessTokenAndCreateNewUser($code, $shopUrl, $shopifyAppConfig)
    {
        $accessToken = $this->shopify
            ->setKey($shopifyAppConfig['key'])
            ->setSecret($shopifyAppConfig['secret'])
            ->setShopUrl($shopUrl)
            ->getAccessToken($code);

        // store permanent token in DB
        $shopifyUser = ShopifyUser::where('shop_url', $shopUrl)->with('scriptTags');

        // @todo call shopify to get shop info

        if ($shopifyUser->count() === 0) {
            $shopifyUser = ShopifyUser::updateOrCreate([
                'shop_url' => $shopUrl,
                'shop_name' => '',
                'shop_domain' => '',
                'app_name' => '',
                'access_token' => '',
            ]);
        } else {
            $shopifyUser = $shopifyUser->get()->first();
        }

        $shopifyApp = ShopifyApp::firstOrCreate([
            'name' => $shopifyAppConfig['name'],
            'slug' => $shopifyAppConfig['name'],
        ]);

        ShopifyAppUsers::firstOrCreate([
            'shopify_users_id' => $shopifyUser->id,
            'shopify_app_id' => $shopifyApp->id,
            'access_token' => $accessToken,
            'scope' => implode(",", $shopifyAppConfig['scope']),
            'shopify_app_name' => $shopifyAppConfig['name'],
        ]);

        return [
            'user' => $shopifyUser,
            'access_token' => $accessToken,
        ];
    }

    public function createScriptTagIfNotInDatabase($shopUrl, $accessToken, $shopifyUser, array $scriptTags, $shopifyAppConfig)
    {
        // if script tag already exists in DB, return true
        foreach ($shopifyUser->scriptTags as $tag) {
            if ($tag->shopify_app === $shopifyAppConfig['name']) return true;
        }

        $scriptTag = $this->shopify
            ->setKey($shopifyAppConfig['key'])
            ->setSecret($shopifyAppConfig['secret'])
            ->setShopUrl($shopUrl)
            ->setAccessToken($accessToken)
            ->post('admin/script_tags.json', $scriptTags);

        ShopifyScriptTag::create([
            'shop_url' => $shopUrl,
            'script_tag_id' => $scriptTag->get('id'),
            'shopify_users_id' => $shopifyUser->id,
            'shopify_app' => $shopifyAppConfig['name'],
        ]);

        return true;
    }

    public function checkAndAddWebhookForUninstall($appName, $accessToken, $shopifyUser, $shopifyAppConfig)
    {
        // if webhook already exists in DB, return true
        foreach ($shopifyUser->webhooks as $hook) {
            if ($tag->shopify_app === $shopifyAppConfig['name']) return true;
        }

        $uninstallWebhook = [
            "webhook" => [
                "topic": "app/uninstalled",
                "address": url('webhooks/' . $appName . '/uninstalled'),
                "format": "json"
            ],
        ];

        ShopifyWebhooks::create([
            'shop_url' => $shopUrl,
            'webhook_id' => $scriptTag->get('id'),
            'shopify_users_id' => $shopifyUser->id,
            'shopify_app' => $shopifyAppConfig['name'],
        ]);

        $scriptTag = $this->shopify
            ->setKey($shopifyAppConfig['key'])
            ->setSecret($shopifyAppConfig['secret'])
            ->setShopUrl($shopUrl)
            ->setAccessToken($accessToken)
            ->post('admin/webhooks.json', $uninstallWebhook);
    }

    // returns user or null
    public function getUserForApp($shop, $appName)
    {
        return ShopifyUser::where('shop_url', $shop)
            ->with([
                'shopifyAppUsers' => function ($query) use ($appName) {
                    $query->where('shopify_app_name', $appName);
                }
            ])
            ->get()
            ->last();
    }
}
