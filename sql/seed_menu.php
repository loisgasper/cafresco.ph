<?php
// Starter Cafresco menu, from the printed menu. Loaded once via POST /api/setup/seed-menu.
// Each item: [name, hot price or null, iced 16oz price or null, customer favorite, short description]
return [
    // Extra descriptions for names used in Loyverse, matched case-insensitively (used by setup/fill-descriptions).
    'extra' => [
        'Capuccino' => [true, 'Espresso with milk and a cloud of foam. Rich, airy and perfectly balanced.'],
        'Matcha' => [true, 'Earthy green tea whisked into milk. Calm, creamy and lightly sweet.'],
        'Dark Choco' => [true, 'Deeper, bittersweet cocoa for true chocolate lovers.'],
        'Blueberry' => [false, 'Blueberry and milk with a fruity, gently tangy finish.'],
        'Mango' => [false, 'Mango and milk. Light, sunny and smooth.'],
        'Taro' => [false, 'Creamy purple yam (ube) milk. Nutty, sweet and very Pinoy.'],
        'Butterscotch' => [false, 'Brown sugar and butter notes swirled into espresso and milk. Warm and mellow.'],
        'Macadamia Nut' => [false, 'Smooth, buttery macadamia flavor in a creamy espresso latte.'],
        'Nutella Latte' => [false, 'Chocolate hazelnut spread blended into an iced latte. Rich and dessert-like.'],
        'White Mocha' => [false, 'Espresso with creamy white chocolate and milk. Sweet, silky and smooth.'],
    ],
    'addons' => [
        'name' => 'Add-ons',
        'items' => [['Extra espresso shot', 60], ['Oat milk', 35], ['Foam', 30], ['Sweet foam', 35], ['Sea salt foam', 35], ['Syrup', 30]],
    ],
    'categories' => [
        ['name' => 'Coffee', 'addons' => true, 'items' => [
            ['Spanish Latte', 115, 120, true, 'Espresso with milk and a touch of condensed sweetness. Creamy, rich and our house favorite.'],
            ['Spanish Latte Cloud', 125, 130, true, 'Our Spanish latte crowned with a soft, airy cloud of sweet foam.'],
            ['Salted Caramel', 135, 140, false, 'Buttery caramel with a pinch of salt, folded into espresso and milk.'],
            ['Caramel Macchiato', 135, 140, false, 'Vanilla milk marked with espresso and finished with a caramel drizzle.'],
            ['Mocha', 135, 140, true, 'Espresso, chocolate and milk. Cozy, chocolatey and smooth.'],
            ['Biscoff Latte', null, 190, true, 'Iced latte with Biscoff cookie spread. Sweet, spiced and a little indulgent.'],
            ['Vanilla Latte', 125, 130, false, 'A classic latte with a gentle sweetness of vanilla.'],
            ['Hazelnut', 135, 140, false, 'Toasty hazelnut flavor blended with espresso and milk.'],
            ['Toffee', 145, 150, false, 'Deep, buttery toffee notes in a smooth espresso latte.'],
            ['Honey Latte', null, 150, false, 'Iced latte sweetened with honey. Soft, floral and mellow.'],
        ]],
        ['name' => 'Classic', 'addons' => true, 'items' => [
            ['Americano', 90, 100, false, 'Espresso softened with hot or iced water. Clean, bold and quietly strong.'],
            ['Americano Sweet Foam', null, 120, false, 'Iced americano topped with a layer of sweet cream foam.'],
            ['Orange Americano', null, 120, false, 'Bright orange juice meets bold espresso. Zesty and refreshing.'],
            ['Honey Americano', null, 130, true, 'Iced americano with a drizzle of honey for a smooth, gentle sweetness.'],
            ['Latte', 100, 120, false, 'Espresso blended with steamed or iced milk. Smooth, creamy and comforting in every sip.'],
            ['Cappuccino', 110, 120, true, 'Espresso with milk and a cloud of foam. Rich, airy and perfectly balanced.'],
        ]],
        ['name' => 'Matcha Series', 'addons' => true, 'items' => [
            ['Matcha Latte', 115, 120, true, 'Earthy green tea whisked into milk. Calm, creamy and lightly sweet.'],
            ['Dirty Matcha', null, 160, false, 'Matcha latte with a shot of espresso poured on top. Two favorites in one.'],
            ['Strawberry Matcha', null, 160, true, 'Layers of strawberry, milk and matcha. Fruity, pretty and refreshing.'],
            ['Mango Matcha', null, 160, false, 'Sweet mango with creamy matcha. A tropical twist on green tea.'],
        ]],
        ['name' => 'Non-Coffee', 'addons' => true, 'items' => [
            ['Chocolate', null, 120, true, 'Iced milk chocolate. Rich, sweet and nostalgic.'],
            ['Dark Chocolate', null, 120, true, 'Deeper, bittersweet cocoa for true chocolate lovers.'],
            ['Strawberry Bliss', null, 100, true, 'Strawberry and milk. Sweet, creamy and pink.'],
            ['Blueberry Latte', null, 100, false, 'Blueberry and milk with a fruity, gently tangy finish.'],
            ['Mango Mist', null, 100, false, 'Mango and milk. Light, sunny and smooth.'],
            ['Cookies & Cream', null, 100, false, 'Crushed cookies blended into creamy milk.'],
            ['Taro (Ube)', null, 90, false, 'Creamy purple yam milk. Nutty, sweet and very Pinoy.'],
        ]],
    ],
];
