-- Extensions and shared functions
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

-- UUIDv7: time-ordered UUIDs (index-friendly primary keys)
CREATE OR REPLACE FUNCTION uuid_generate_v7() RETURNS uuid AS $$
DECLARE
    unix_ts_ms bytea;
    uuid_bytes bytea;
BEGIN
    unix_ts_ms := substring(int8send((extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3);
    uuid_bytes := uuid_send(gen_random_uuid());
    uuid_bytes := overlay(uuid_bytes PLACING unix_ts_ms FROM 1 FOR 6);
    uuid_bytes := set_byte(uuid_bytes, 6, (b'0111' || get_byte(uuid_bytes, 6)::bit(4))::bit(8)::int);
    uuid_bytes := set_byte(uuid_bytes, 8, (b'10'   || get_byte(uuid_bytes, 8)::bit(6))::bit(8)::int);
    RETURN encode(uuid_bytes, 'hex')::uuid;
END
$$ LANGUAGE plpgsql VOLATILE;

-- updated_at maintenance
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

-- Immutability guard: attach to append-only tables
CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is forbidden: table is append-only', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'raise_exception';
END
$$ LANGUAGE plpgsql;
