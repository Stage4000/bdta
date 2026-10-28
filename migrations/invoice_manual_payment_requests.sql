-- Explicit reviewed migration only; never run from the payment request/bootstrap.
-- Live invoices.id and invoice_payments.id are signed INT(11), InnoDB.
-- No historical records are enrolled or rewritten. No automatic rollback/drop.
CREATE TABLE invoice_manual_payment_requests (
    request_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_id INT NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_cents BIGINT NOT NULL,
    payment_id INT NULL,
    result_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_manual_request_invoice (invoice_id),
    KEY idx_manual_request_payment (payment_id),
    CONSTRAINT fk_manual_request_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    CONSTRAINT fk_manual_request_payment FOREIGN KEY (payment_id) REFERENCES invoice_payments(id)
) ENGINE=InnoDB;
