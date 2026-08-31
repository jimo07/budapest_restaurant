UPDATE categories SET name_en='Mains', name_hu='Főételek' WHERE id=1;
UPDATE categories SET name_en='Soups', name_hu='Levesek' WHERE id=2;
UPDATE categories SET name_en='Drinks', name_hu='Italok' WHERE id=3;
UPDATE products SET name_en='Hungarian Beef Rice', name_hu='Magyar marhahúsos rizs', description_en='Classic beef, peppers and spiced rice', description_hu='Klasszikus marhahús, paprika és fűszeres rizs' WHERE id=1;
UPDATE products SET name_en='Goulash Soup', name_hu='Gulyásleves', description_en='Traditional Hungarian flavour', description_hu='Hagyományos magyar ízek' WHERE id=2;
UPDATE products SET name_en='Homemade Lemonade', name_hu='Házi limonádé', description_en='Made fresh daily', description_hu='Naponta frissen készítve' WHERE id=3;
UPDATE products SET name_en='Budapest Classic Set', name_hu='Budapest klasszikus menü', description_en='Main, soup and drink', description_hu='Főétel, leves és ital' WHERE id=4;
UPDATE delivery_zones SET name_en='City centre delivery zone', name_hu='Belvárosi kiszállítási zóna' WHERE id=1;
