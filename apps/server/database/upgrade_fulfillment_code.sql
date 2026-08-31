UPDATE orders SET fulfillment_code = LPAD(id, 6, '0') WHERE fulfillment_code IS NULL;
