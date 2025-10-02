--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: greenify; Type: SCHEMA; Schema: -; Owner: greenify_admin
--

CREATE SCHEMA greenify;


ALTER SCHEMA greenify OWNER TO greenify_admin;

--
-- Name: prezzo_dom; Type: DOMAIN; Schema: greenify; Owner: greenify_admin
--

CREATE DOMAIN greenify.prezzo_dom AS numeric(8,2)
	CONSTRAINT prezzo_dom_check CHECK ((VALUE >= (0)::numeric));


ALTER DOMAIN greenify.prezzo_dom OWNER TO greenify_admin;

--
-- Name: sconto_dom; Type: DOMAIN; Schema: greenify; Owner: greenify_admin
--

CREATE DOMAIN greenify.sconto_dom AS integer
	CONSTRAINT sconto_dom_check CHECK ((VALUE = ANY (ARRAY[0, 5, 15, 30])));


ALTER DOMAIN greenify.sconto_dom OWNER TO greenify_admin;

--
-- Name: stato; Type: DOMAIN; Schema: greenify; Owner: greenify_admin
--

CREATE DOMAIN greenify.stato AS character varying(20)
	CONSTRAINT stato_check CHECK (((VALUE)::text = ANY (ARRAY[('Attivo'::character varying)::text, ('Scaduta'::character varying)::text])));


ALTER DOMAIN greenify.stato OWNER TO greenify_admin;

--
-- Name: fn_calcola_data_consegna_random(date); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_calcola_data_consegna_random(data_base date DEFAULT CURRENT_DATE) RETURNS date
    LANGUAGE plpgsql
    AS $$
DECLARE
    giorni INT := (FLOOR(random() * 3) + 2)::INT; -- 2, 3 o 4
    data_consegna DATE := data_base;
    aggiunti INT := 0;
BEGIN
    WHILE aggiunti < giorni LOOP
        data_consegna := data_consegna + INTERVAL '1 day';
        -- DOW = Day Of Week
        IF EXTRACT(DOW FROM data_consegna) <> 0 THEN -- 0 = domenica
            aggiunti := aggiunti + 1;
        END IF;
    END LOOP;
    RETURN data_consegna;
END;
$$;


ALTER FUNCTION greenify.fn_calcola_data_consegna_random(data_base date) OWNER TO greenify_admin;

--
-- Name: fn_calcola_totale_fattura(bigint); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_calcola_totale_fattura(p_fattura_id bigint) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_totale NUMERIC := 0;
    v_sconto_pct INTEGER := 0;
    v_totale_scontato NUMERIC := 0;
BEGIN
    -- Calcola il totale dei prodotti
    SELECT SUM(quantita * prezzo) INTO v_totale
    FROM greenify.fattura_contiene_prodotto
    WHERE fattura_id = p_fattura_id;

    -- Recupera la percentuale di sconto dalla fattura
    SELECT sconto_pct INTO v_sconto_pct
    FROM greenify.fattura
    WHERE id = p_fattura_id;

    -- Applica lo sconto se presente
    IF v_sconto_pct > 0 THEN
        v_totale_scontato := v_totale - (v_totale * v_sconto_pct / 100);
    ELSE
        v_totale_scontato := v_totale;
    END IF;

    RETURN COALESCE(v_totale_scontato, 0);
END;
$$;


ALTER FUNCTION greenify.fn_calcola_totale_fattura(p_fattura_id bigint) OWNER TO greenify_admin;

--
-- Name: fn_crea_ordine(bigint, character varying, json); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_crea_ordine(p_negozio_id bigint, p_fornitore_piva character varying, p_prodotti json) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_ordine_id bigint;
    v_prodotto json;
    v_prodotto_id bigint;
    v_quantita integer;
    v_prezzo numeric;
BEGIN
    -- Inserisci l'ordine SENZA specificare l'id (gestito dalla sequence)
    INSERT INTO greenify.ordine (negozio_id, fornitore_piva)
    VALUES (p_negozio_id, p_fornitore_piva)
    RETURNING id INTO v_ordine_id;

    -- Inserisci i prodotti dell'ordine
    -- Per ogni prodotto nell'array JSON, estrai i dati e inseriscili nella tabella ordine_contiene_prodotto
    FOR v_prodotto IN SELECT * FROM json_array_elements(p_prodotti)
    LOOP
        v_prodotto_id := (v_prodotto->>'prodotto_id')::bigint;
        v_quantita := (v_prodotto->>'quantita')::integer;
        v_prezzo := (v_prodotto->>'prezzo')::numeric;
        INSERT INTO greenify.ordine_contiene_prodotto (ordine_id, prodotto_id, quantita, prezzo)
        VALUES (v_ordine_id, v_prodotto_id, v_quantita, v_prezzo);
    END LOOP;

    RETURN v_ordine_id;
END;
$$;


ALTER FUNCTION greenify.fn_crea_ordine(p_negozio_id bigint, p_fornitore_piva character varying, p_prodotti json) OWNER TO greenify_admin;

--
-- Name: fn_inserisci_fattura_e_prodotti(character, bigint, bigint[], integer[], greenify.prezzo_dom[], integer); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_inserisci_fattura_e_prodotti(p_cliente_cf character, p_negozio_id bigint, p_prodotti bigint[], p_quantita integer[], p_prezzi greenify.prezzo_dom[], p_sconto_pct integer) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
    new_fattura_id BIGINT;
    i INT;
BEGIN
    INSERT INTO greenify.fattura (totale_pagato, cliente_cf, negozio_id, sconto_pct)
    VALUES (0, p_cliente_cf, p_negozio_id, p_sconto_pct)
    RETURNING id INTO new_fattura_id;

    FOR i IN 1 .. array_length(p_prodotti, 1) LOOP
        INSERT INTO greenify.fattura_contiene_prodotto (fattura_id, prodotto_id, quantita, prezzo)
        VALUES (new_fattura_id, p_prodotti[i], p_quantita[i], p_prezzi[i]);
    END LOOP;

    -- Aggiorna il totale_pagato dopo aver inserito tutti i prodotti
    UPDATE greenify.fattura
    SET totale_pagato = greenify.fn_calcola_totale_fattura(new_fattura_id)
    WHERE id = new_fattura_id;

    RETURN new_fattura_id;
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Errore in inserisci_fattura_e_prodotti: %', SQLERRM;
        RAISE;
END;
$$;


ALTER FUNCTION greenify.fn_inserisci_fattura_e_prodotti(p_cliente_cf character, p_negozio_id bigint, p_prodotti bigint[], p_quantita integer[], p_prezzi greenify.prezzo_dom[], p_sconto_pct integer) OWNER TO greenify_admin;

--
-- Name: fn_inserisci_fornitore_con_indirizzo(character varying, character varying, character varying, character varying, character varying, character varying); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_inserisci_fornitore_con_indirizzo(p_piva character varying, p_nome character varying, p_via character varying, p_citta character varying, p_telefono character varying, p_email character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_indirizzo_id bigint;
BEGIN
    -- Inserisci sempre un nuovo indirizzo, anche se già presente
    INSERT INTO greenify.indirizzo (indirizzo, citta)
    VALUES (p_via, p_citta)
    RETURNING id INTO v_indirizzo_id;

    -- Inserisci fornitore
    INSERT INTO greenify.fornitore (p_iva, nome, indirizzo_id, telefono, email, attivo)
    VALUES (p_piva, p_nome, v_indirizzo_id, p_telefono, p_email, true);
END;
$$;


ALTER FUNCTION greenify.fn_inserisci_fornitore_con_indirizzo(p_piva character varying, p_nome character varying, p_via character varying, p_citta character varying, p_telefono character varying, p_email character varying) OWNER TO greenify_admin;

--
-- Name: fn_inserisci_tessera_e_rilascia(character, bigint, integer); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_inserisci_tessera_e_rilascia(p_cliente_cf character, p_negozio_id bigint, p_punti_iniziali integer DEFAULT 0) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
    nuova_tessera_id BIGINT;
BEGIN
    INSERT INTO greenify.tessera (data_scadenza, punti, attiva, negozio_id, cliente_cf)
    VALUES (CURRENT_DATE + INTERVAL '2 years', p_punti_iniziali, true, p_negozio_id, p_cliente_cf)
    RETURNING id INTO nuova_tessera_id;

    INSERT INTO greenify.rilascia (negozio_id, tessera_id)
    VALUES (p_negozio_id, nuova_tessera_id);

    RETURN nuova_tessera_id;
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Errore in inserisci_tessera_e_rilascia: %', SQLERRM;
        RAISE;
END;
$$;


ALTER FUNCTION greenify.fn_inserisci_tessera_e_rilascia(p_cliente_cf character, p_negozio_id bigint, p_punti_iniziali integer) OWNER TO greenify_admin;

--
-- Name: fn_inserisci_utente_cliente(character varying, character varying, character varying, character, character varying, character varying, date); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_inserisci_utente_cliente(p_mail character varying, p_password character varying, p_telefono character varying, p_cf character, p_nome character varying, p_cognome character varying, p_data_nascita date) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Inizio transazione implicita nella funzione
    INSERT INTO greenify.utente (mail, password, telefono)
    VALUES (p_mail, p_password, p_telefono);

    INSERT INTO greenify.cliente (cf, nome, cognome, data_nascita, mail)
    VALUES (p_cf, p_nome, p_cognome, p_data_nascita, p_mail);
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Errore in inserisci_utente_cliente: %', SQLERRM;
        RAISE;
END;
$$;


ALTER FUNCTION greenify.fn_inserisci_utente_cliente(p_mail character varying, p_password character varying, p_telefono character varying, p_cf character, p_nome character varying, p_cognome character varying, p_data_nascita date) OWNER TO greenify_admin;

--
-- Name: fn_modifica_cliente(character varying, character varying, character varying, character varying, character varying, character varying, character varying, date); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_modifica_cliente(old_cf character varying, old_mail character varying, new_cf character varying, new_mail character varying, new_nome character varying, new_cognome character varying, new_telefono character varying, new_data_nascita date) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE greenify.utente u
    SET mail = new_mail, telefono = new_telefono
    WHERE u.mail = old_mail;

    UPDATE greenify.cliente c
    SET cf = new_cf, nome = new_nome, cognome = new_cognome, data_nascita = new_data_nascita
    WHERE c.cf = old_cf;

    RETURN true;
END;
$$;


ALTER FUNCTION greenify.fn_modifica_cliente(old_cf character varying, old_mail character varying, new_cf character varying, new_mail character varying, new_nome character varying, new_cognome character varying, new_telefono character varying, new_data_nascita date) OWNER TO greenify_admin;

--
-- Name: fn_modifica_fornitore(character varying, character varying, character varying, character varying, character varying, character varying, character varying); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_modifica_fornitore(old_p_iva character varying, new_p_iva character varying, new_nome character varying, new_indirizzo character varying, new_citta character varying, new_telefono character varying, new_email character varying) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_indirizzo_id bigint;
BEGIN
    -- Recupera indirizzo_id associato al fornitore
    SELECT indirizzo_id INTO v_indirizzo_id FROM greenify.fornitore WHERE p_iva = old_p_iva;
    IF v_indirizzo_id IS NULL THEN
        RAISE EXCEPTION 'Fornitore non trovato';
    END IF;

    -- Aggiorna indirizzo
    UPDATE greenify.indirizzo
    SET indirizzo = new_indirizzo, citta = new_citta
    WHERE id = v_indirizzo_id;

    -- Aggiorna fornitore
    UPDATE greenify.fornitore
    SET p_iva = new_p_iva,
        nome = new_nome,
        telefono = new_telefono,
        email = new_email
    WHERE p_iva = old_p_iva;

    RETURN true;
END;
$$;


ALTER FUNCTION greenify.fn_modifica_fornitore(old_p_iva character varying, new_p_iva character varying, new_nome character varying, new_indirizzo character varying, new_citta character varying, new_telefono character varying, new_email character varying) OWNER TO greenify_admin;

--
-- Name: fn_modifica_negozio(bigint, text, character varying, character varying); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_modifica_negozio(p_negozio_id bigint, p_indirizzo text, p_citta character varying, p_telefono character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_indirizzo_id bigint;
BEGIN
    SELECT indirizzo_id INTO v_indirizzo_id FROM greenify.negozio WHERE id = p_negozio_id;
    IF v_indirizzo_id IS NULL THEN
        RAISE EXCEPTION 'Nessun indirizzo associato al negozio %', p_negozio_id;
    END IF;
    UPDATE greenify.indirizzo
    SET indirizzo = p_indirizzo, citta = p_citta
    WHERE id = v_indirizzo_id;

    UPDATE greenify.negozio
    SET telefono = p_telefono
    WHERE id = p_negozio_id;
END;
$$;


ALTER FUNCTION greenify.fn_modifica_negozio(p_negozio_id bigint, p_indirizzo text, p_citta character varying, p_telefono character varying) OWNER TO greenify_admin;

--
-- Name: fn_opzioni_sconto_per_punti(integer); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_opzioni_sconto_per_punti(p_punti integer) RETURNS TABLE(sconto_pct integer, punti_richiesti integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF p_punti >= 300 THEN
        RETURN QUERY VALUES (30, 300), (15, 200), (5, 100);
    ELSIF p_punti >= 200 THEN
        RETURN QUERY VALUES (15, 200), (5, 100);
    ELSIF p_punti >= 100 THEN
        RETURN QUERY VALUES (5, 100);
    END IF;
END;
$$;


ALTER FUNCTION greenify.fn_opzioni_sconto_per_punti(p_punti integer) OWNER TO greenify_admin;

--
-- Name: fn_orari_negozio(bigint); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_orari_negozio(p_negozio_id bigint) RETURNS TABLE(giorno character varying, ora_inizio time without time zone, ora_fine time without time zone)
    LANGUAGE plpgsql STABLE
    AS $$
BEGIN
    RETURN QUERY
    SELECT o.giorno, o.ora_inizio, o.ora_fine
    FROM greenify.orario o
    WHERE o.negozio_id = p_negozio_id
    ORDER BY 
        -- CASE per ordinare i giorni della settimana
        CASE o.giorno
            WHEN 'Lunedì' THEN 1
            WHEN 'Martedì' THEN 2
            WHEN 'Mercoledì' THEN 3
            WHEN 'Giovedì' THEN 4
            WHEN 'Venerdì' THEN 5
            WHEN 'Sabato' THEN 6
            WHEN 'Domenica' THEN 7
            ELSE 8
        END;
END;
$$;


ALTER FUNCTION greenify.fn_orari_negozio(p_negozio_id bigint) OWNER TO greenify_admin;

--
-- Name: fn_statistiche_negozio(bigint, integer, integer); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_statistiche_negozio(p_negozio_id bigint, p_year integer, p_period integer) RETURNS TABLE(totale_vendite numeric, totale_uscite numeric)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_start date;
    v_end date;
    v_period integer;
BEGIN
    IF p_year IS NOT NULL THEN
        v_start := make_date(p_year, 1, 1);
        v_end := make_date(p_year, 12, 31);
    ELSE
        v_period := COALESCE(p_period, 30);
        v_start := CURRENT_DATE - (v_period || ' days')::interval;
        v_end := NULL;
    END IF;

    RETURN QUERY
    SELECT
        -- Totale vendite filtrate
        (SELECT COALESCE(SUM(totale_pagato),0)
         FROM greenify.fattura
         WHERE negozio_id = p_negozio_id
           AND (
                (v_end IS NOT NULL AND data_acquisto BETWEEN v_start AND v_end)
                OR (v_end IS NULL AND data_acquisto >= v_start)
           )
        ),
        -- Totale uscite filtrate
        (SELECT COALESCE(SUM(ocp.quantita * ocp.prezzo),0)
         FROM greenify.ordine o
         JOIN greenify.ordine_contiene_prodotto ocp ON o.id = ocp.ordine_id
         WHERE o.negozio_id = p_negozio_id
           AND (
                (v_end IS NOT NULL AND o.data_ordine BETWEEN v_start AND v_end)
                OR (v_end IS NULL AND o.data_ordine >= v_start)
           )
        );
END;
$$;


ALTER FUNCTION greenify.fn_statistiche_negozio(p_negozio_id bigint, p_year integer, p_period integer) OWNER TO greenify_admin;

--
-- Name: fn_storico_ordini_negozio(bigint, character varying); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.fn_storico_ordini_negozio(p_negozio_id bigint, p_fornitore_piva character varying) RETURNS TABLE(id bigint, data_ordine timestamp without time zone, data_consegna date, fornitore_piva character varying, nome character varying, articoli json, totale numeric)
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Raggruppa i prodotti dell'ordine in un array JSON, dove ogni elemento contiene
    -- il nome del prodotto, la quantità ordinata e il prezzo unitario, ordinati per nome prodotto.
    RETURN QUERY
        SELECT o.id, o.data_ordine, o.data_consegna, o.fornitore_piva, f.nome,
            json_agg(json_build_object(
                'nome', p.nome,
                'quantita', ocp.quantita,
                'prezzo', ocp.prezzo
            ) ORDER BY p.nome) AS articoli,
            SUM(ocp.quantita * ocp.prezzo) AS totale
        FROM greenify.ordine o
        JOIN greenify.fornitore f ON o.fornitore_piva = f.p_iva
        JOIN greenify.ordine_contiene_prodotto ocp ON ocp.ordine_id = o.id
        JOIN greenify.prodotto p ON ocp.prodotto_id = p.id
        WHERE o.negozio_id = p_negozio_id
          AND (p_fornitore_piva IS NULL OR f.p_iva = p_fornitore_piva)
        GROUP BY o.id, o.data_ordine, o.data_consegna, o.fornitore_piva, f.nome
        ORDER BY o.data_ordine DESC
        LIMIT 30;
END;
$$;


ALTER FUNCTION greenify.fn_storico_ordini_negozio(p_negozio_id bigint, p_fornitore_piva character varying) OWNER TO greenify_admin;

--
-- Name: tg_aggiorna_disponibilita_negozio(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_aggiorna_disponibilita_negozio() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_negozio_id BIGINT;
BEGIN
    SELECT negozio_id INTO v_negozio_id FROM greenify.ordine WHERE id = NEW.ordine_id;
    IF EXISTS (SELECT 1 FROM greenify.dispone WHERE negozio_id = v_negozio_id AND prodotto_id = NEW.prodotto_id) THEN
        UPDATE greenify.dispone
        SET quantita = quantita + NEW.quantita, prezzo = NEW.prezzo
        WHERE negozio_id = v_negozio_id AND prodotto_id = NEW.prodotto_id;
    ELSE
        INSERT INTO greenify.dispone (negozio_id, prodotto_id, quantita, prezzo)
        VALUES (v_negozio_id, NEW.prodotto_id, NEW.quantita, NEW.prezzo);
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_aggiorna_disponibilita_negozio() OWNER TO greenify_admin;

--
-- Name: tg_aggiorna_disponibilita_prodotto(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_aggiorna_disponibilita_prodotto() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_negozio_id BIGINT;
    v_quantita_corrente INT;
BEGIN
    SELECT f.negozio_id INTO v_negozio_id FROM greenify.fattura f WHERE f.id = NEW.fattura_id;
    IF v_negozio_id IS NULL THEN
        RAISE EXCEPTION 'Impossibile determinare il negozio della fattura per aggiornare la disponibilità.';
    END IF;

    SELECT quantita INTO v_quantita_corrente
    FROM greenify.dispone
    WHERE negozio_id = v_negozio_id AND prodotto_id = NEW.prodotto_id;

    IF v_quantita_corrente IS NOT NULL THEN
        IF v_quantita_corrente - NEW.quantita < 0 THEN
            RAISE EXCEPTION 'Disponibilità insufficiente per il prodotto % nel negozio %', NEW.prodotto_id, v_negozio_id;
        END IF;
        UPDATE greenify.dispone
        SET quantita = v_quantita_corrente - NEW.quantita
        WHERE negozio_id = v_negozio_id AND prodotto_id = NEW.prodotto_id;
    ELSE
        IF 0 - NEW.quantita < 0 THEN
            RAISE EXCEPTION 'Disponibilità insufficiente per il prodotto % nel negozio %', NEW.prodotto_id, v_negozio_id;
        END IF;
        INSERT INTO greenify.dispone (negozio_id, prodotto_id, quantita, prezzo)
        VALUES (v_negozio_id, NEW.prodotto_id, 0 - NEW.quantita, NEW.prezzo);
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_aggiorna_disponibilita_prodotto() OWNER TO greenify_admin;

--
-- Name: tg_aggiorna_punti_tessera_post_fattura(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_aggiorna_punti_tessera_post_fattura() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    tessera_id BIGINT;
    punti_attuali INT;
    punti_ottenuti INT;
    punti_spesi INT := 0;
    nuovi_punti INT;
BEGIN
    -- Esegui solo se totale_pagato passa da 0 (o NULL) a un valore > 0
    -- TG_OP indica il tipo di operazione (UPDATE)
    IF (TG_OP = 'UPDATE') AND (COALESCE(OLD.totale_pagato,0) >= COALESCE(NEW.totale_pagato,0)) THEN
        RETURN NEW;
    END IF;

    -- Calcola punti spesi in base allo sconto_pct
    IF NEW.sconto_pct = 5 THEN punti_spesi := 100;
    ELSIF NEW.sconto_pct = 15 THEN punti_spesi := 200;
    ELSIF NEW.sconto_pct = 30 THEN punti_spesi := 300;
    END IF;

    punti_ottenuti := FLOOR(COALESCE(NEW.totale_pagato, 0));

    -- Cerca la tessera attiva più recente per il cliente
    SELECT id, punti INTO tessera_id, punti_attuali
    FROM greenify.tessera
    WHERE cliente_cf = NEW.cliente_cf AND attiva = true
    ORDER BY data_scadenza DESC, id DESC
    LIMIT 1;

    IF tessera_id IS NULL THEN
        RETURN NEW;
    END IF;

    -- Se i punti spesi sono maggiori dei punti attuali, azzera prima i punti
    IF punti_spesi > punti_attuali THEN
        nuovi_punti := punti_ottenuti;
    ELSE
        nuovi_punti := punti_attuali - punti_spesi + punti_ottenuti;
    END IF;

    UPDATE greenify.tessera
    SET punti = nuovi_punti
    WHERE id = tessera_id;

    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_aggiorna_punti_tessera_post_fattura() OWNER TO greenify_admin;

--
-- Name: tg_aggiorna_quantita_fornitore(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_aggiorna_quantita_fornitore() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE greenify.fornisce
    SET quantita = quantita - NEW.quantita
    WHERE prodotto_id = NEW.prodotto_id AND fornitore_piva = (SELECT fornitore_piva FROM greenify.ordine WHERE id = NEW.ordine_id);
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_aggiorna_quantita_fornitore() OWNER TO greenify_admin;

--
-- Name: tg_check_disponibilita_prodotto_fattura(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_disponibilita_prodotto_fattura() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    disp INT;
    v_negozio_id BIGINT;
    v_prezzo_disp NUMERIC;
BEGIN
    -- Recupera il negozio della fattura
    SELECT negozio_id INTO v_negozio_id FROM greenify.fattura WHERE id = NEW.fattura_id;
    IF v_negozio_id IS NULL THEN
        RAISE EXCEPTION 'Impossibile determinare il negozio della fattura.';
    END IF;

    -- Recupera la disponibilità e il prezzo del prodotto per quel negozio
    SELECT quantita, prezzo INTO disp, v_prezzo_disp FROM greenify.dispone
    WHERE negozio_id = v_negozio_id AND prodotto_id = NEW.prodotto_id;

   
    -- Se la disponibilità è nulla o minore della quantità richiesta, errore
    IF disp IS NULL THEN
	RAISE EXCEPTION 'disp is null';	
	END IF;	
	 IF disp < NEW.quantita THEN
        RAISE EXCEPTION 'Quantità insufficiente: disp=%, richiesta=%, negozio=%,prezzo=%,prod_id=%', disp, NEW.quantita, v_negozio_id,v_prezzo_disp,NEW.prodotto_id;
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_disponibilita_prodotto_fattura() OWNER TO greenify_admin;

--
-- Name: tg_check_fattura_has_products(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_fattura_has_products() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    cnt INT;
BEGIN
    SELECT COUNT(*) INTO cnt FROM greenify.fattura_contiene_prodotto WHERE fattura_id = NEW.id;
    IF cnt = 0 THEN
        RAISE EXCEPTION 'Una fattura deve avere almeno un prodotto associato.';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_fattura_has_products() OWNER TO greenify_admin;

--
-- Name: tg_check_fattura_negozio_aperto(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_fattura_negozio_aperto() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    negozio_aperto BOOLEAN;
BEGIN
    SELECT aperto INTO negozio_aperto FROM greenify.negozio WHERE id = NEW.negozio_id;
    IF NOT COALESCE(negozio_aperto, FALSE) THEN
        RAISE EXCEPTION 'Non è possibile emettere una fattura per un negozio chiuso.';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_fattura_negozio_aperto() OWNER TO greenify_admin;

--
-- Name: tg_check_indirizzo_esclusivo_fornitore(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_indirizzo_esclusivo_fornitore() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM greenify.negozio WHERE indirizzo_id = NEW.indirizzo_id) THEN
        RAISE EXCEPTION 'L''indirizzo % è già associato a un negozio.', NEW.indirizzo_id;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_indirizzo_esclusivo_fornitore() OWNER TO greenify_admin;

--
-- Name: tg_check_indirizzo_esclusivo_negozio(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_indirizzo_esclusivo_negozio() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM greenify.fornitore WHERE indirizzo_id = NEW.indirizzo_id) THEN
        RAISE EXCEPTION 'L''indirizzo % è già associato a un fornitore.', NEW.indirizzo_id;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_indirizzo_esclusivo_negozio() OWNER TO greenify_admin;

--
-- Name: tg_check_manager_max_one_open_store(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_manager_max_one_open_store() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    cnt INT;
BEGIN
    IF NEW.manager_mail IS NOT NULL AND (NEW.aperto IS TRUE OR NEW.aperto IS NULL) THEN
        SELECT COUNT(*) INTO cnt FROM greenify.negozio
        WHERE manager_mail = NEW.manager_mail AND aperto = TRUE
        AND id <> COALESCE(NEW.id, -1);
        IF cnt > 0 THEN
            RAISE EXCEPTION 'Un manager può gestire al massimo un negozio aperto (% già assegnato)', NEW.manager_mail;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_manager_max_one_open_store() OWNER TO greenify_admin;

--
-- Name: tg_check_orario_null_consistency(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_orario_null_consistency() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF (NEW.ora_inizio IS NULL AND NEW.ora_fine IS NOT NULL)
     OR (NEW.ora_inizio IS NOT NULL AND NEW.ora_fine IS NULL) THEN
    RAISE EXCEPTION 'ora_inizio e ora_fine devono essere entrambi NULL o entrambi valorizzati';
  END IF;
  RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_orario_null_consistency() OWNER TO greenify_admin;

--
-- Name: tg_check_ordine_con_prodotti(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_ordine_con_prodotti() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Dopo l'inserimento di un ordine, controlla che esista almeno una riga in ordine_contiene_prodotto
    IF NOT EXISTS (
        SELECT 1 FROM greenify.ordine_contiene_prodotto WHERE ordine_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'Non è possibile inserire un ordine senza prodotti associati';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_ordine_con_prodotti() OWNER TO greenify_admin;

--
-- Name: tg_check_tessera_negozio_aperto(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_tessera_negozio_aperto() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    negozio_aperto BOOLEAN;
BEGIN
    SELECT aperto INTO negozio_aperto FROM greenify.negozio WHERE id = NEW.negozio_id;
    IF NOT COALESCE(negozio_aperto, FALSE) THEN
        RAISE EXCEPTION 'Non è possibile emettere una tessera per un negozio chiuso.';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_tessera_negozio_aperto() OWNER TO greenify_admin;

--
-- Name: tg_check_tessera_unica_cliente(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_check_tessera_unica_cliente() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.attiva = true THEN
        IF EXISTS (
            SELECT 1 FROM greenify.tessera
            WHERE cliente_cf = NEW.cliente_cf
              AND attiva = true
              AND id <> NEW.id
        ) THEN
            RAISE EXCEPTION 'Il cliente % ha già una tessera attiva.', NEW.cliente_cf;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_check_tessera_unica_cliente() OWNER TO greenify_admin;

--
-- Name: tg_delete_utente_on_cliente_delete(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_delete_utente_on_cliente_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    DELETE FROM greenify.utente WHERE mail = OLD.mail;
    RETURN OLD;
END;
$$;


ALTER FUNCTION greenify.tg_delete_utente_on_cliente_delete() OWNER TO greenify_admin;

--
-- Name: tg_disattiva_riattiva_tessere_cliente(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--
CREATE OR REPLACE FUNCTION greenify.tg_disattiva_riattiva_tessere_cliente() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    ultima_tessera_id BIGINT;
BEGIN
    IF NEW.attivo = FALSE AND (OLD.attivo IS TRUE OR OLD.attivo IS NULL) THEN
        UPDATE greenify.tessera
        SET attiva = FALSE
        WHERE cliente_cf = (SELECT cf FROM greenify.cliente WHERE mail = NEW.mail);
    ELSIF NEW.attivo = TRUE AND (OLD.attivo IS FALSE OR OLD.attivo IS NULL) THEN
        -- Riattiva solo la tessera più recente non scaduta
        SELECT id INTO ultima_tessera_id
        FROM greenify.tessera
        WHERE cliente_cf = (SELECT cf FROM greenify.cliente WHERE mail = NEW.mail)
          AND data_scadenza > CURRENT_DATE
        ORDER BY data_scadenza DESC, id DESC
        LIMIT 1;

        IF ultima_tessera_id IS NOT NULL THEN
            UPDATE greenify.tessera
            SET attiva = TRUE
            WHERE id = ultima_tessera_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$;




ALTER FUNCTION greenify.tg_disattiva_riattiva_tessere_cliente() OWNER TO greenify_admin;

--
-- Name: tg_disattiva_tessera_scaduta(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_disattiva_tessera_scaduta() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.data_scadenza <= CURRENT_DATE THEN
        NEW.attiva := FALSE;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_disattiva_tessera_scaduta() OWNER TO greenify_admin;


-- Name: tg_disattiva_tessere_negozio(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_disattiva_tessere_negozio() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.aperto = FALSE AND (OLD.aperto IS TRUE OR OLD.aperto IS NULL) THEN
        UPDATE greenify.tessera
        SET attiva = FALSE
        WHERE negozio_id = NEW.id;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_disattiva_tessere_negozio() OWNER TO greenify_admin;

--
-- Name: tg_disponi_prezzo_aumento(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_disponi_prezzo_aumento() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.prezzo := ROUND(NEW.prezzo * 1.4, 2);
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_disponi_prezzo_aumento() OWNER TO greenify_admin;

--
-- Name: tg_reset_data_licenziamento_manager(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_reset_data_licenziamento_manager() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Solo se l'utente è attivo e il manager esiste
    IF NEW.attivo = TRUE THEN
        UPDATE greenify.manager
        SET data_licenziamento = NULL
        WHERE mail = NEW.mail;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_reset_data_licenziamento_manager() OWNER TO greenify_admin;

--
-- Name: tg_riattiva_tessere_negozio(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_riattiva_tessere_negozio() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.aperto = TRUE AND (OLD.aperto IS FALSE OR OLD.aperto IS NULL) THEN
        -- Riattiva tutte le tessere non scadute
        UPDATE greenify.tessera
        SET attiva = TRUE
        WHERE negozio_id = NEW.id
          AND data_scadenza > CURRENT_DATE;
        -- Poni a NULL la data di chiusura
        NEW.data_chiusura := NULL;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_riattiva_tessere_negozio() OWNER TO greenify_admin;

--
-- Name: tg_set_data_chiusura_negozio(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_set_data_chiusura_negozio() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.aperto = FALSE AND (OLD.aperto IS TRUE OR OLD.aperto IS NULL) THEN
        NEW.data_chiusura := CURRENT_DATE;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_set_data_chiusura_negozio() OWNER TO greenify_admin;

--
-- Name: tg_set_data_licenziamento_manager(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_set_data_licenziamento_manager() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Se l'utente viene disattivato
    IF OLD.attivo = TRUE AND NEW.attivo = FALSE THEN
        -- Aggiorna la data_licenziamento solo se esiste il manager associato
        UPDATE greenify.manager
        SET data_licenziamento = CURRENT_DATE
        WHERE mail = NEW.mail
          AND data_licenziamento IS NULL;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_set_data_licenziamento_manager() OWNER TO greenify_admin;

--
-- Name: tg_set_manager_attivo_false(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.tg_set_manager_attivo_false() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.data_licenziamento IS NOT NULL THEN
        NEW.attivo := FALSE;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.tg_set_manager_attivo_false() OWNER TO greenify_admin;

--
-- Name: trg_set_data_licenziamento_manager(); Type: FUNCTION; Schema: greenify; Owner: greenify_admin
--

CREATE FUNCTION greenify.trg_set_data_licenziamento_manager() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.attivo = true AND NEW.attivo = false THEN
        UPDATE greenify.manager
        SET data_licenziamento = CURRENT_DATE
        WHERE mail = NEW.mail AND data_licenziamento IS NULL;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION greenify.trg_set_data_licenziamento_manager() OWNER TO greenify_admin;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cliente; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.cliente (
    cf character varying(16) NOT NULL,
    nome character varying(100) NOT NULL,
    cognome character varying(100) NOT NULL,
    data_nascita date,
    data_iscrizione date DEFAULT CURRENT_DATE NOT NULL,
    mail character varying(255) NOT NULL
);


ALTER TABLE greenify.cliente OWNER TO greenify_admin;

--
-- Name: indirizzo; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.indirizzo (
    id bigint NOT NULL,
    indirizzo text NOT NULL,
    citta character varying(100) NOT NULL
);


ALTER TABLE greenify.indirizzo OWNER TO greenify_admin;

--
-- Name: negozio; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.negozio (
    id bigint NOT NULL,
    data_chiusura date,
    aperto boolean DEFAULT true NOT NULL,
    telefono character varying(10),
    manager_mail character varying(255),
    indirizzo_id bigint NOT NULL
);


ALTER TABLE greenify.negozio OWNER TO greenify_admin;

--
-- Name: rilascia; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.rilascia (
    negozio_id bigint NOT NULL,
    tessera_id bigint NOT NULL,
    data_rilascio timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE greenify.rilascia OWNER TO greenify_admin;

--
-- Name: tessera; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.tessera (
    id bigint NOT NULL,
    data_scadenza date NOT NULL,
    punti integer DEFAULT 0 NOT NULL,
    attiva boolean DEFAULT true NOT NULL,
    negozio_id bigint,
    cliente_cf character varying(16),
    CONSTRAINT tessera_punti_check CHECK ((punti >= 0))
);


ALTER TABLE greenify.tessera OWNER TO greenify_admin;

--
-- Name: ClientiPremium; Type: VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE VIEW greenify."ClientiPremium" AS
 SELECT c.cf,
    c.nome,
    c.cognome,
    c.mail,
    t.punti,
    i.citta AS citta_negozio_emissione,
    n.id AS negozio_id,
    r.data_rilascio AS data_emissione
   FROM ((((greenify.tessera t
     JOIN greenify.cliente c ON (((t.cliente_cf)::text = (c.cf)::text)))
     JOIN greenify.negozio n ON ((t.negozio_id = n.id)))
     JOIN greenify.indirizzo i ON ((n.indirizzo_id = i.id)))
     JOIN greenify.rilascia r ON ((r.tessera_id = t.id)))
  WHERE ((t.attiva = true) AND (t.punti > 300))
  ORDER BY t.punti DESC;


ALTER VIEW greenify."ClientiPremium" OWNER TO greenify_admin;

--
-- Name: manager; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.manager (
    mail character varying(255) NOT NULL,
    data_assunzione date DEFAULT CURRENT_DATE NOT NULL,
    data_licenziamento date
);


ALTER TABLE greenify.manager OWNER TO greenify_admin;

--
-- Name: utente; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.utente (
    mail character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    telefono character varying(10) NOT NULL,
    attivo boolean DEFAULT true
);


ALTER TABLE greenify.utente OWNER TO greenify_admin;

--
-- Name: ManagerAttivi; Type: VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE VIEW greenify."ManagerAttivi" AS
 SELECT m.mail,
    m.data_assunzione
   FROM (greenify.manager m
     JOIN greenify.utente u ON (((m.mail)::text = (u.mail)::text)))
  WHERE (u.attivo = true);


ALTER VIEW greenify."ManagerAttivi" OWNER TO greenify_admin;

--
-- Name: dispone; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.dispone (
    negozio_id bigint NOT NULL,
    prodotto_id bigint NOT NULL,
    quantita integer NOT NULL,
    prezzo greenify.prezzo_dom NOT NULL,
    CONSTRAINT dispone_quantita_check CHECK ((quantita >= 0))
);


ALTER TABLE greenify.dispone OWNER TO greenify_admin;

--
-- Name: fattura; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.fattura (
    id bigint NOT NULL,
    data_acquisto timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    sconto_pct greenify.sconto_dom DEFAULT 0 NOT NULL,
    totale_pagato greenify.prezzo_dom NOT NULL,
    cliente_cf character varying(16),
    negozio_id bigint,
    CONSTRAINT fattura_totale_pagato_check CHECK (((totale_pagato)::numeric >= (0)::numeric))
);


ALTER TABLE greenify.fattura OWNER TO greenify_admin;

--
-- Name: fattura_contiene_prodotto; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.fattura_contiene_prodotto (
    fattura_id bigint NOT NULL,
    prodotto_id bigint NOT NULL,
    quantita integer NOT NULL,
    prezzo greenify.prezzo_dom NOT NULL,
    CONSTRAINT fattura_contiene_prodotto_quantita_check CHECK ((quantita > 0))
);


ALTER TABLE greenify.fattura_contiene_prodotto OWNER TO greenify_admin;

--
-- Name: fattura_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.fattura ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.fattura_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: fornisce; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.fornisce (
    prodotto_id bigint NOT NULL,
    fornitore_piva character varying(20) NOT NULL,
    costo greenify.prezzo_dom NOT NULL,
    quantita integer NOT NULL,
    CONSTRAINT fornisce_quantita_check CHECK ((quantita >= 0))
);


ALTER TABLE greenify.fornisce OWNER TO greenify_admin;

--
-- Name: fornitore; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.fornitore (
    p_iva character varying(20) NOT NULL,
    nome character varying(100) NOT NULL,
    telefono character varying(10) NOT NULL,
    email character varying(255),
    attivo boolean DEFAULT true NOT NULL,
    indirizzo_id bigint NOT NULL
);


ALTER TABLE greenify.fornitore OWNER TO greenify_admin;

--
-- Name: indirizzo_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.indirizzo ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.indirizzo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: negozio_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.negozio ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.negozio_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: orario; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.orario (
    giorno character varying(10) NOT NULL,
    negozio_id bigint NOT NULL,
    ora_inizio time without time zone,
    ora_fine time without time zone,
    CONSTRAINT orario_check CHECK ((ora_inizio < ora_fine))
);


ALTER TABLE greenify.orario OWNER TO greenify_admin;

--
-- Name: ordine; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.ordine (
    id bigint NOT NULL,
    data_consegna date DEFAULT CURRENT_DATE NOT NULL,
    negozio_id bigint NOT NULL,
    fornitore_piva character varying(20) NOT NULL,
    data_ordine timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE greenify.ordine OWNER TO greenify_admin;

--
-- Name: ordine_contiene_prodotto; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.ordine_contiene_prodotto (
    ordine_id bigint NOT NULL,
    prodotto_id bigint NOT NULL,
    quantita integer NOT NULL,
    prezzo greenify.prezzo_dom NOT NULL,
    CONSTRAINT ordine_contiene_prodotto_prezzo_check CHECK (((prezzo)::numeric >= (0)::numeric)),
    CONSTRAINT ordine_contiene_prodotto_quantita_check CHECK ((quantita > 0))
);


ALTER TABLE greenify.ordine_contiene_prodotto OWNER TO greenify_admin;

--
-- Name: ordine_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.ordine ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.ordine_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: prodotto; Type: TABLE; Schema: greenify; Owner: greenify_admin
--

CREATE TABLE greenify.prodotto (
    id bigint NOT NULL,
    nome character varying(100) NOT NULL,
    descrizione character varying(250)
);


ALTER TABLE greenify.prodotto OWNER TO greenify_admin;

--
-- Name: prodotti_fornitori; Type: VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE VIEW greenify.prodotti_fornitori AS
 SELECT p.id AS prodotto_id,
    p.nome,
    p.descrizione,
    f.fornitore_piva,
    f.costo,
    f.quantita
   FROM ((greenify.prodotto p
     JOIN greenify.fornisce f ON ((f.prodotto_id = p.id)))
     JOIN greenify.fornitore fo ON (((f.fornitore_piva)::text = (fo.p_iva)::text)))
  WHERE ((fo.attivo = true) AND (f.quantita > 0))
  ORDER BY p.nome, f.costo;


ALTER VIEW greenify.prodotti_fornitori OWNER TO greenify_admin;

--
-- Name: prodotto_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.prodotto ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.prodotto_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: storico_tessere; Type: VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE VIEW greenify.storico_tessere AS
 SELECT t.id AS tessera_id,
    t.punti,
    r.data_rilascio,
    n.id AS negozio_id,
    i.citta,
    c.nome,
    c.cognome
   FROM ((((greenify.tessera t
     JOIN greenify.negozio n ON ((t.negozio_id = n.id)))
     JOIN greenify.indirizzo i ON ((n.indirizzo_id = i.id)))
     JOIN greenify.rilascia r ON ((r.tessera_id = t.id)))
     JOIN greenify.cliente c ON (((t.cliente_cf)::text = (c.cf)::text)))
  WHERE (n.aperto = false)
  ORDER BY n.id, r.data_rilascio DESC;


ALTER VIEW greenify.storico_tessere OWNER TO greenify_admin;

--
-- Name: tessera_id_seq; Type: SEQUENCE; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE greenify.tessera ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME greenify.tessera_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: tessere_complete; Type: VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE VIEW greenify.tessere_complete AS
 SELECT c.nome,
    c.cognome,
    t.id,
    t.punti,
    t.data_scadenza,
    n.id AS negozio_id,
    i.citta,
    i.indirizzo,
    t.attiva
   FROM (((greenify.tessera t
     JOIN greenify.cliente c ON (((t.cliente_cf)::text = (c.cf)::text)))
     JOIN greenify.negozio n ON ((t.negozio_id = n.id)))
     JOIN greenify.indirizzo i ON ((n.indirizzo_id = i.id)))
  ORDER BY t.id DESC;


ALTER VIEW greenify.tessere_complete OWNER TO greenify_admin;

--
-- Name: vm_statistiche_negozio_totali; Type: MATERIALIZED VIEW; Schema: greenify; Owner: greenify_admin
--

CREATE MATERIALIZED VIEW greenify.vm_statistiche_negozio_totali AS
 SELECT id AS negozio_id,
    COALESCE(( SELECT sum((f2.totale_pagato)::numeric) AS sum
           FROM greenify.fattura f2
          WHERE (f2.negozio_id = n.id)), (0)::numeric) AS totale_vendite_all,
    COALESCE(( SELECT sum(((ocp2.quantita)::numeric * (ocp2.prezzo)::numeric)) AS sum
           FROM (greenify.ordine o2
             JOIN greenify.ordine_contiene_prodotto ocp2 ON ((o2.id = ocp2.ordine_id)))
          WHERE (o2.negozio_id = n.id)), (0)::numeric) AS totale_uscite_all
   FROM greenify.negozio n
  WITH NO DATA;


ALTER MATERIALIZED VIEW greenify.vm_statistiche_negozio_totali OWNER TO greenify_admin;

--
-- Data for Name: cliente; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.cliente (cf, nome, cognome, data_nascita, data_iscrizione, mail) FROM stdin;
DNTPRC79L18I441Q	Pericle	Donati	1979-05-30	2025-08-27	orettarinaldi@susumart.com
GLLRDL67P54I452P	Rodolfo	Galli	1967-11-30	2024-03-20	ruggieroaudenic@nguyenlieu24h.com
TRSDVD98I41I490N	Davide	Torisi	1998-09-20	2025-08-28	torisi.davide@gmal.com
VTLLDA70L17F421M	Lidia	Vitale	1970-09-26	2024-02-17	gmilani@code-gmail.com
BNCICP03P441P344	Iacopo1	Bianco	2003-03-03	2025-08-29	bianchi.jacopo@gmail.com
\.


--
-- Data for Name: dispone; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.dispone (negozio_id, prodotto_id, quantita, prezzo) FROM stdin;
1	14	4	4.00
1	4	3	33.60
1	15	10	2.80
1	19	18	8.50
1	8	3	13.30
1	12	1	7.00
1	5	10	7.70
5	13	10	4.48
4	9	21	14.00
4	7	54	5.60
4	18	5	16.80
4	5	30	7.00
3	4	3	33.60
3	12	21	9.80
3	3	14	25.20
5	12	4	10.08
5	19	4	12.60
2	14	10	3.64
4	11	8	6.72
2	8	10	14.00
2	15	6	4.20
3	16	5	9.10
4	13	10	4.20
3	7	11	4.90
3	17	2	21.00
5	14	71	3.50
2	1	6	16.10
1	20	3	25.20
1	1	4	14.00
1	13	15	4.34
5	2	8	11.90
5	18	3	15.40
2	2	21	11.20
2	16	14	8.40
2	11	5	6.30
2	6	4	16.80
\.


--
-- Data for Name: fattura; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.fattura (id, data_acquisto, sconto_pct, totale_pagato, cliente_cf, negozio_id) FROM stdin;
1	2024-02-18 09:12:35.65	0	40.50	VTLLDA70L17F421M	3
2	2024-10-15 16:37:03.976	0	62.00	VTLLDA70L17F421M	5
3	2025-01-03 12:27:22.571	5	47.50	VTLLDA70L17F421M	1
4	2025-02-20 14:29:27.689	0	23.00	VTLLDA70L17F421M	2
5	2024-03-20 12:35:23.576	0	230.20	GLLRDL67P54I452P	1
6	2024-05-16 09:27:54.163	0	174.00	GLLRDL67P54I452P	3
7	2024-09-20 18:16:19.499	30	40.25	GLLRDL67P54I452P	5
8	2025-08-28 16:30:14.231074	0	36.00	TRSDVD98I41I490N	4
9	2025-08-28 16:30:28.607808	0	185.00	TRSDVD98I41I490N	3
10	2025-08-28 16:30:53.707832	0	82.80	TRSDVD98I41I490N	5
11	2025-08-29 16:33:18.491325	0	91.00	VTLLDA70L17F421M	2
12	2025-08-30 09:45:27.66474	0	18.30	VTLLDA70L17F421M	1
13	2025-08-30 09:45:45.656026	0	7.00	VTLLDA70L17F421M	1
14	2025-08-30 09:47:08.818164	5	19.29	VTLLDA70L17F421M	1
15	2025-08-30 09:47:47.190291	0	14.70	VTLLDA70L17F421M	1
16	2025-08-30 10:50:56.654601	0	4.48	DNTPRC79L18I441Q	5
\.


--
-- Data for Name: fattura_contiene_prodotto; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.fattura_contiene_prodotto (fattura_id, prodotto_id, quantita, prezzo) FROM stdin;
1	7	1	3.50
1	12	1	7.00
1	17	2	15.00
2	2	1	8.50
2	14	7	2.50
2	19	4	9.00
3	20	1	18.00
3	10	1	22.00
3	1	1	10.00
4	1	2	11.50
5	10	2	22.00
5	20	2	18.00
5	1	5	10.00
5	8	3	9.50
5	19	3	8.50
5	13	6	3.10
5	5	4	5.50
5	15	2	2.80
6	3	6	18.00
6	4	1	24.00
6	12	6	7.00
7	19	3	9.00
7	2	1	8.50
7	18	2	11.00
8	9	1	10.00
8	7	1	4.00
8	18	1	12.00
8	5	2	5.00
9	4	1	24.00
9	12	5	7.00
9	3	7	18.00
10	12	4	7.20
10	19	6	9.00
11	2	1	11.20
11	16	2	8.40
11	11	2	6.30
11	6	3	16.80
12	12	1	7.00
12	15	1	2.80
12	19	1	8.50
13	12	1	7.00
14	12	1	7.00
14	8	1	13.30
15	12	1	7.00
15	5	1	7.70
16	13	1	4.48
\.


--
-- Data for Name: fornisce; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.fornisce (prodotto_id, fornitore_piva, costo, quantita) FROM stdin;
12	34345678902	7.00	34
4	34345678902	24.00	3
19	34123456780	8.50	18
1	34123456780	10.00	22
1	34234567891	11.50	10
2	34234567891	8.00	37
3	34345678902	18.00	49
5	34123456780	5.50	18
5	34456789013	5.00	39
6	34234567891	12.00	9
7	34345678902	3.50	27
7	34456789013	4.00	57
8	34123456780	9.50	9
8	34234567891	10.00	12
9	34456789013	10.00	27
10	34123456780	22.00	7
11	34456789013	4.80	10
11	34234567891	4.50	9
13	34123456780	3.10	26
13	34456789013	3.00	12
14	34234567891	2.60	12
15	34234567891	3.00	8
16	34345678902	6.50	6
16	34234567891	6.00	20
17	34345678902	15.00	5
18	34456789013	12.00	14
15	34123456780	2.80	5
14	34567890125	2.50	112
2	34567890125	8.50	12
12	34567890125	7.20	10
13	34567890125	3.20	14
18	34567890125	11.00	7
19	34567890125	9.00	21
\.


--
-- Data for Name: fornitore; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.fornitore (p_iva, nome, telefono, email, attivo, indirizzo_id) FROM stdin;
34123456780	Vivaio Rossi	3471234567	info@vivaiorossi.it	t	6
34234567891	Piante & Co	3482345678	contatti@pianteco.it	t	7
34456789013	Green World	3474567890	info@greenworld.it	t	9
34345678902	Floridea	3493456789	ordini@floridea.it	f	8
34567890125	Garden Center	3485678901	info@gardencenter.it	t	10
\.


--
-- Data for Name: indirizzo; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.indirizzo (id, indirizzo, citta) FROM stdin;
1	Via Roma 10	Milano
11	ttt	ttt
8	Via Manzoni 7	Bergamo
10	Via Cavour 33	Catania
2	Corso Italia 22	Torino
3	Via Verdi 5	Firenze
4	Piazza Garibaldi 1	Napoli
5	Via Dante 99	Bologna
6	Via Mazzini 12	Genova
7	Viale Europa 45	Padova
9	Via Libertà 88	Palermo
\.


--
-- Data for Name: manager; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.manager (mail, data_assunzione, data_licenziamento) FROM stdin;
luca@greenify.com	2024-12-01	\N
giulia@greenify.com	2025-08-13	\N
manuel@greenify.com	2025-08-13	\N
gennaro@greenify.com	2025-08-13	2025-08-29
arturo@greenify.com	2025-08-16	\N
\.


--
-- Data for Name: negozio; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.negozio (id, data_chiusura, aperto, telefono, manager_mail, indirizzo_id) FROM stdin;
4	2025-08-29	f	3577264080	gennaro@greenify.com	4
2	\N	t	3577264080	luca@greenify.com	2
1	\N	t	3816363740	arturo@greenify.com	1
3	\N	t	3568438828	giulia@greenify.com	3
5	\N	t	3468379424	manuel@greenify.com	5
\.


--
-- Data for Name: orario; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.orario (giorno, negozio_id, ora_inizio, ora_fine) FROM stdin;
Lunedì	2	\N	\N
Martedì	2	10:00:00	20:00:00
Mercoledì	2	10:00:00	20:00:00
Giovedì	2	10:00:00	20:00:00
Venerdì	2	10:00:00	20:00:00
Sabato	2	10:00:00	20:00:00
Domenica	2	10:00:00	13:00:00
Lunedì	3	08:30:00	12:30:00
Martedì	3	08:30:00	12:30:00
Mercoledì	3	08:30:00	12:30:00
Giovedì	3	08:30:00	12:30:00
Venerdì	3	08:30:00	12:30:00
Sabato	3	08:30:00	12:30:00
Domenica	3	\N	\N
Lunedì	4	09:00:00	17:00:00
Martedì	4	09:00:00	17:00:00
Mercoledì	4	09:00:00	17:00:00
Giovedì	4	09:00:00	17:00:00
Venerdì	4	09:00:00	17:00:00
Sabato	4	\N	\N
Domenica	4	\N	\N
Lunedì	5	10:00:00	19:30:00
Martedì	5	10:00:00	19:30:00
Mercoledì	5	10:00:00	19:30:00
Giovedì	5	10:00:00	19:30:00
Venerdì	5	10:00:00	19:30:00
Sabato	5	10:00:00	19:30:00
Domenica	5	\N	\N
Lunedì	1	09:01:00	19:00:00
Mercoledì	1	09:00:00	19:00:00
Giovedì	1	09:00:00	19:00:00
Venerdì	1	09:00:00	19:00:00
Sabato	1	09:00:00	13:00:00
\.


--
-- Data for Name: ordine; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.ordine (id, data_consegna, negozio_id, fornitore_piva, data_ordine) FROM stdin;
21	2023-01-10	1	34123456780	2023-01-05 00:00:00
22	2023-01-18	2	34234567891	2023-01-12 00:00:00
23	2023-01-25	3	34345678902	2023-01-20 00:00:00
24	2023-02-05	4	34456789013	2023-01-30 00:00:00
26	2023-02-25	1	34123456780	2023-02-20 00:00:00
27	2023-03-05	2	34234567891	2023-02-28 00:00:00
28	2023-03-15	3	34345678902	2023-03-10 00:00:00
29	2023-03-25	4	34456789013	2023-03-20 00:00:00
31	2023-04-15	1	34123456780	2023-04-10 00:00:00
32	2023-05-01	2	34234567891	2023-04-25 00:00:00
33	2023-05-10	3	34345678902	2023-05-05 00:00:00
34	2023-06-01	4	34456789013	2023-05-25 00:00:00
36	2023-04-10	1	34123456780	2023-04-05 00:00:00
37	2023-04-18	2	34234567891	2023-04-12 00:00:00
38	2023-05-02	3	34345678902	2023-04-27 00:00:00
39	2023-05-15	4	34456789013	2023-05-10 00:00:00
1	2023-01-10	1	34123456780	2023-01-05 00:00:00
2	2023-01-18	2	34234567891	2023-01-12 00:00:00
3	2023-01-25	3	34345678902	2023-01-20 00:00:00
4	2023-02-05	4	34456789013	2023-01-30 00:00:00
6	2023-02-25	1	34123456780	2023-02-20 00:00:00
7	2023-03-05	2	34234567891	2023-02-28 00:00:00
8	2023-03-15	3	34345678902	2023-03-10 00:00:00
9	2023-03-25	4	34456789013	2023-03-20 00:00:00
11	2023-04-15	1	34123456780	2023-04-10 00:00:00
12	2023-05-01	2	34234567891	2023-04-25 00:00:00
13	2023-05-10	3	34345678902	2023-05-05 00:00:00
14	2023-06-01	4	34456789013	2023-05-25 00:00:00
16	2023-04-10	1	34123456780	2023-04-05 00:00:00
17	2023-04-18	2	34234567891	2023-04-12 00:00:00
18	2023-05-02	3	34345678902	2023-04-27 00:00:00
19	2023-05-15	4	34456789013	2023-05-10 00:00:00
41	2025-09-02	1	34345678902	2025-08-29 12:46:30.497039
42	2025-09-01	1	34345678902	2025-08-29 13:06:57.803857
43	2025-09-02	1	34345678902	2025-08-29 13:07:24.982696
44	2025-09-01	1	34345678902	2025-08-29 13:08:03.533934
46	2025-09-01	1	34345678902	2025-08-29 18:54:07.818653
47	2025-09-01	1	34123456780	2025-08-29 18:54:07.825219
25	2023-02-15	5	34567890125	2023-02-10 00:00:00
30	2023-04-05	5	34567890125	2023-03-30 00:00:00
35	2023-07-01	5	34567890125	2023-06-25 00:00:00
40	2023-06-01	5	34567890125	2023-05-27 00:00:00
5	2023-02-15	5	34567890125	2023-02-10 00:00:00
10	2023-04-05	5	34567890125	2023-03-30 00:00:00
15	2023-07-01	5	34567890125	2023-06-25 00:00:00
20	2023-06-01	5	34567890125	2023-05-27 00:00:00
45	2025-09-01	1	34567890125	2025-08-29 15:25:07.391975
\.


--
-- Data for Name: ordine_contiene_prodotto; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.ordine_contiene_prodotto (ordine_id, prodotto_id, quantita, prezzo) FROM stdin;
1	1	10	10.00
1	5	15	5.50
1	19	8	8.50
2	2	12	8.00
2	6	7	12.00
2	14	10	2.60
3	3	9	18.00
3	4	5	24.00
3	12	8	7.00
4	7	20	4.00
4	9	10	10.00
4	11	8	4.80
5	14	20	2.50
5	18	5	11.00
5	2	10	8.50
6	13	12	3.10
6	20	6	18.00
6	8	7	9.50
7	1	8	11.50
7	8	10	10.00
7	15	6	3.00
8	3	10	18.00
8	16	5	6.50
8	17	4	15.00
9	5	18	5.00
9	7	15	4.00
9	13	10	3.00
10	14	15	2.50
10	12	8	7.20
10	19	7	9.00
11	10	5	22.00
11	15	8	2.80
11	13	9	3.10
12	2	10	8.00
12	16	8	6.00
12	11	7	4.50
13	3	8	18.00
13	12	10	7.00
13	7	12	3.50
14	9	12	10.00
14	5	14	5.00
14	18	6	12.00
15	14	18	2.50
15	13	11	3.20
15	19	10	9.00
16	19	10	8.50
17	16	8	6.00
18	12	15	7.00
19	7	20	4.00
20	14	25	2.50
41	12	1	7.00
42	12	1	7.00
43	12	1	7.00
44	12	1	7.00
45	14	4	2.50
46	12	2	7.00
46	4	3	24.00
47	19	4	8.50
47	15	5	2.80
\.


--
-- Data for Name: prodotto; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.prodotto (id, nome, descrizione) FROM stdin;
3	Orchidea Phalaenopsis	Orchidea dai fiori eleganti
4	Bonsai Ficus	Bonsai da interno
5	Lavanda	Pianta aromatica e decorativa
6	Sansevieria	Pianta resistente e purificante
7	Cactus Echinopsis	Cactus da interno
8	Felce Boston	Pianta da interno verde brillante
9	Pothos	Pianta rampicante da interno
10	Kentia	Palma ornamentale da appartamento
11	Geranio	Pianta fiorita da esterno
12	Begonia Rex	Pianta ornamentale a foglia colorata
13	Rosmarino	Aromatica per cucina
14	Basilico	Aromatica fresca
15	Mentha Piperita	Menta profumata
16	Ciclamino	Pianta fiorita invernale
17	Ortensia	Pianta fiorita a cespuglio
18	Ibisco	Pianta fiorita tropicale
19	Gelsomino	Pianta rampicante profumata
20	Zamioculcas	Pianta da interno resistente
2	Aloe Vera	Pianta succulenta con proprietà lenitive
1	Ficus Benjamina	Pianta ornamentale da interno
125	tete	te
124	tes	te33
\.


--
-- Data for Name: rilascia; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.rilascia (negozio_id, tessera_id, data_rilascio) FROM stdin;
3	1	2024-02-18 09:12:35.65
1	2	2024-03-20 12:35:23.576
4	3	2025-08-28 16:30:14.225091
5	4	2025-08-30 10:50:56.651646
\.


--
-- Data for Name: tessera; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.tessera (id, data_scadenza, punti, attiva, negozio_id, cliente_cf) FROM stdin;
3	2027-08-28	303	t	4	TRSDVD98I41I490N
1	2026-02-18	121	t	3	VTLLDA70L17F421M
4	2027-08-30	4	t	5	DNTPRC79L18I441Q
2	2027-08-28	144	f	1	GLLRDL67P54I452P
\.


--
-- Data for Name: utente; Type: TABLE DATA; Schema: greenify; Owner: greenify_admin
--

COPY greenify.utente (mail, password, telefono, attivo) FROM stdin;
orettarinaldi@susumart.com	$2y$12$yEsqggPfYcu.wrc1lQj1tOAsU.ZpLS/s0cjg/a9Sk7m4WOnt7mEHq	3478928473	t
ruggieroaudenic@nguyenlieu24h.com	$2y$12$nOj/iP4DpXewPBnxAARQ7O3VWAiCL5PzNpvCQ3h.9B7XjbDoRu4tq	3789417420	t
manuel@greenify.com	$2y$12$3alpl2BrJREHhaUwaSJwgOIfl4/9UutZXYS/5nXHt6fug0GuMs/U.	2946870243	t
gmilani@code-gmail.com	$2y$12$JqPeYHfsJyWIhd0WuW0o2.WNy8u.Xm5TQm01FORBXarJv.qiNSQz.	3748496723	t
bianchi.jacopo@gmail.com	$2y$12$IFT47SEwyWUKFbUBIc5YeesfgEnw1hPg2fdM/IS0c1mW3Ke4dE54u	3479917367	t
luca@greenify.com	$2y$12$HqKJTwFxwe6H52OBM9qDuejS.Y8HT21jS9IcD8afcGFRpLS5RQJUG	4991293879	t
torisi.davide@gmal.com	$2y$12$m77Ykvi28Z8fAWvd7TlE7.lK/ew4EybVBfNJkJRKD2yI47E8T87x2	3789263481	t
giulia@greenify.com	$2y$12$dlR9mFS7/ELcX.Z3gKrS8uJ8XhONgCYu1WzTLTKCjz5fQQQqZg/22	7335838509	t
gennaro@greenify.com	$2y$12$HeL.v5ZYci/nKomPZi7fhuh0xJtmreUL7Uz715Hu7Ub89h8AfcSSG	6175854300	f
arturo@greenify.com	$2y$12$Ghs8S9lGwSRwFCjy18gaNuYa9GFryK4mVnprCU9an3PoHVI17g6PS	2230076379	t
\.


--
-- Name: fattura_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.fattura_id_seq', 16, true);


--
-- Name: indirizzo_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.indirizzo_id_seq', 13, true);


--
-- Name: negozio_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.negozio_id_seq', 5, true);


--
-- Name: ordine_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.ordine_id_seq', 47, true);


--
-- Name: prodotto_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.prodotto_id_seq', 125, true);


--
-- Name: tessera_id_seq; Type: SEQUENCE SET; Schema: greenify; Owner: greenify_admin
--

SELECT pg_catalog.setval('greenify.tessera_id_seq', 4, true);


--
-- Name: cliente cliente_mail_key; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.cliente
    ADD CONSTRAINT cliente_mail_key UNIQUE (mail);


--
-- Name: cliente cliente_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.cliente
    ADD CONSTRAINT cliente_pkey PRIMARY KEY (cf);


--
-- Name: dispone dispone_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.dispone
    ADD CONSTRAINT dispone_pkey PRIMARY KEY (negozio_id, prodotto_id);


--
-- Name: fattura_contiene_prodotto fattura_contiene_prodotto_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura_contiene_prodotto
    ADD CONSTRAINT fattura_contiene_prodotto_pkey PRIMARY KEY (fattura_id, prodotto_id);


--
-- Name: fattura fattura_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura
    ADD CONSTRAINT fattura_pkey PRIMARY KEY (id);


--
-- Name: fornisce fornisce_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornisce
    ADD CONSTRAINT fornisce_pkey PRIMARY KEY (prodotto_id, fornitore_piva);


--
-- Name: fornitore fornitore_email_key; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornitore
    ADD CONSTRAINT fornitore_email_key UNIQUE (email);


--
-- Name: fornitore fornitore_indirizzo_id_unique; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornitore
    ADD CONSTRAINT fornitore_indirizzo_id_unique UNIQUE (indirizzo_id);


--
-- Name: fornitore fornitore_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornitore
    ADD CONSTRAINT fornitore_pkey PRIMARY KEY (p_iva);


--
-- Name: indirizzo indirizzo_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.indirizzo
    ADD CONSTRAINT indirizzo_pkey PRIMARY KEY (id);


--
-- Name: manager manager_pkey1; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.manager
    ADD CONSTRAINT manager_pkey1 PRIMARY KEY (mail);


--
-- Name: negozio negozio_indirizzo_id_unique; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.negozio
    ADD CONSTRAINT negozio_indirizzo_id_unique UNIQUE (indirizzo_id);


--
-- Name: negozio negozio_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.negozio
    ADD CONSTRAINT negozio_pkey PRIMARY KEY (id);


--
-- Name: orario orario_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.orario
    ADD CONSTRAINT orario_pkey PRIMARY KEY (giorno, negozio_id);


--
-- Name: ordine_contiene_prodotto ordine_contiene_prodotto_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine_contiene_prodotto
    ADD CONSTRAINT ordine_contiene_prodotto_pkey PRIMARY KEY (ordine_id, prodotto_id);


--
-- Name: ordine ordine_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine
    ADD CONSTRAINT ordine_pkey PRIMARY KEY (id);


--
-- Name: prodotto prodotto_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.prodotto
    ADD CONSTRAINT prodotto_pkey PRIMARY KEY (id);


--
-- Name: rilascia rilascia_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.rilascia
    ADD CONSTRAINT rilascia_pkey PRIMARY KEY (negozio_id, tessera_id);


--
-- Name: tessera tessera_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.tessera
    ADD CONSTRAINT tessera_pkey PRIMARY KEY (id);


--
-- Name: utente utente_pkey; Type: CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.utente
    ADD CONSTRAINT utente_pkey PRIMARY KEY (mail);


--
-- Name: tessera tg_check_tessera_unica_cliente; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER tg_check_tessera_unica_cliente BEFORE INSERT OR UPDATE ON greenify.tessera FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_tessera_unica_cliente();


--
-- Name: dispone tg_disponi_prezzo_aumento; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER tg_disponi_prezzo_aumento BEFORE INSERT ON greenify.dispone FOR EACH ROW EXECUTE FUNCTION greenify.tg_disponi_prezzo_aumento();


--
-- Name: utente tg_set_data_licenziamento_manager; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER tg_set_data_licenziamento_manager AFTER UPDATE OF attivo ON greenify.utente FOR EACH ROW WHEN (((old.attivo = true) AND (new.attivo = false))) EXECUTE FUNCTION greenify.trg_set_data_licenziamento_manager();


--
-- Name: fattura_contiene_prodotto trg_aggiorna_disp_fattura_prodotto; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_aggiorna_disp_fattura_prodotto AFTER INSERT ON greenify.fattura_contiene_prodotto FOR EACH ROW EXECUTE FUNCTION greenify.tg_aggiorna_disponibilita_prodotto();


--
-- Name: ordine_contiene_prodotto trg_aggiorna_disponibilita_negozio; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_aggiorna_disponibilita_negozio AFTER INSERT ON greenify.ordine_contiene_prodotto FOR EACH ROW EXECUTE FUNCTION greenify.tg_aggiorna_disponibilita_negozio();


--
-- Name: fattura trg_aggiorna_punti_tessera; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_aggiorna_punti_tessera AFTER UPDATE OF totale_pagato ON greenify.fattura FOR EACH ROW EXECUTE FUNCTION greenify.tg_aggiorna_punti_tessera_post_fattura();


--
-- Name: ordine_contiene_prodotto trg_aggiorna_quantita_fornitore; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_aggiorna_quantita_fornitore AFTER INSERT ON greenify.ordine_contiene_prodotto FOR EACH ROW EXECUTE FUNCTION greenify.tg_aggiorna_quantita_fornitore();


--
-- Name: fattura_contiene_prodotto trg_check_disp_fattura_prodotto; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_check_disp_fattura_prodotto BEFORE INSERT OR UPDATE ON greenify.fattura_contiene_prodotto FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_disponibilita_prodotto_fattura();


--
-- Name: fornitore trg_check_indirizzo_esclusivo_fornitore; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_check_indirizzo_esclusivo_fornitore BEFORE INSERT OR UPDATE ON greenify.fornitore FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_indirizzo_esclusivo_fornitore();


--
-- Name: negozio trg_check_indirizzo_esclusivo_negozio; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_check_indirizzo_esclusivo_negozio BEFORE INSERT OR UPDATE ON greenify.negozio FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_indirizzo_esclusivo_negozio();


--
-- Name: fattura trg_chk_fattura_negozio_aperto; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_chk_fattura_negozio_aperto BEFORE INSERT ON greenify.fattura FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_fattura_negozio_aperto();


--
-- Name: negozio trg_chk_manager_max_one_open_store; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_chk_manager_max_one_open_store BEFORE INSERT OR UPDATE ON greenify.negozio FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_manager_max_one_open_store();


--
-- Name: orario trg_chk_orario_null_consistency; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_chk_orario_null_consistency BEFORE INSERT OR UPDATE ON greenify.orario FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_orario_null_consistency();


--
-- Name: tessera trg_chk_tessera_negozio_aperto; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_chk_tessera_negozio_aperto BEFORE INSERT ON greenify.tessera FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_tessera_negozio_aperto();


--
-- Name: tessera trg_disattiva_tessera_scaduta; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_disattiva_tessera_scaduta BEFORE INSERT OR UPDATE ON greenify.tessera FOR EACH ROW EXECUTE FUNCTION greenify.tg_disattiva_tessera_scaduta();


--
-- Name: utente trg_disattiva_tessere_cliente; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_disattiva_tessere_cliente
AFTER UPDATE OF attivo ON greenify.utente
FOR EACH ROW EXECUTE FUNCTION greenify.tg_disattiva_riattiva_tessere_cliente();


--
-- Name: negozio trg_disattiva_tessere_negozio; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_disattiva_tessere_negozio AFTER UPDATE OF aperto ON greenify.negozio FOR EACH ROW EXECUTE FUNCTION greenify.tg_disattiva_tessere_negozio();


--
-- Name: fattura trg_fattura_has_products; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE CONSTRAINT TRIGGER trg_fattura_has_products AFTER INSERT OR UPDATE ON greenify.fattura DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION greenify.tg_check_fattura_has_products();


--
-- Name: utente trg_reset_data_licenziamento_manager; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_reset_data_licenziamento_manager AFTER UPDATE OF attivo ON greenify.utente FOR EACH ROW WHEN ((new.attivo = true)) EXECUTE FUNCTION greenify.tg_reset_data_licenziamento_manager();


--
-- Name: negozio trg_riattiva_tessere_negozio; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_riattiva_tessere_negozio BEFORE UPDATE OF aperto ON greenify.negozio FOR EACH ROW EXECUTE FUNCTION greenify.tg_riattiva_tessere_negozio();


--
-- Name: negozio trg_set_data_chiusura_negozio; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_set_data_chiusura_negozio BEFORE UPDATE ON greenify.negozio FOR EACH ROW EXECUTE FUNCTION greenify.tg_set_data_chiusura_negozio();


--
-- Name: utente trg_set_data_licenziamento_manager; Type: TRIGGER; Schema: greenify; Owner: greenify_admin
--

CREATE TRIGGER trg_set_data_licenziamento_manager AFTER UPDATE OF attivo ON greenify.utente FOR EACH ROW EXECUTE FUNCTION greenify.tg_set_data_licenziamento_manager();


--
-- Name: cliente cliente_mail_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.cliente
    ADD CONSTRAINT cliente_mail_fkey FOREIGN KEY (mail) REFERENCES greenify.utente(mail) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: dispone dispone_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.dispone
    ADD CONSTRAINT dispone_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: dispone dispone_prodotto_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.dispone
    ADD CONSTRAINT dispone_prodotto_id_fkey FOREIGN KEY (prodotto_id) REFERENCES greenify.prodotto(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: fattura fattura_cliente_cf_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura
    ADD CONSTRAINT fattura_cliente_cf_fkey FOREIGN KEY (cliente_cf) REFERENCES greenify.cliente(cf) ON UPDATE CASCADE;


--
-- Name: fattura_contiene_prodotto fattura_contiene_prodotto_fattura_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura_contiene_prodotto
    ADD CONSTRAINT fattura_contiene_prodotto_fattura_id_fkey FOREIGN KEY (fattura_id) REFERENCES greenify.fattura(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: fattura_contiene_prodotto fattura_contiene_prodotto_prodotto_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura_contiene_prodotto
    ADD CONSTRAINT fattura_contiene_prodotto_prodotto_id_fkey FOREIGN KEY (prodotto_id) REFERENCES greenify.prodotto(id) ON UPDATE CASCADE;


--
-- Name: fattura fattura_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fattura
    ADD CONSTRAINT fattura_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE;


--
-- Name: fornisce fornisce_fornitore_piva_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornisce
    ADD CONSTRAINT fornisce_fornitore_piva_fkey FOREIGN KEY (fornitore_piva) REFERENCES greenify.fornitore(p_iva) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: fornisce fornisce_prodotto_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornisce
    ADD CONSTRAINT fornisce_prodotto_id_fkey FOREIGN KEY (prodotto_id) REFERENCES greenify.prodotto(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: fornitore fornitore_indirizzo_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.fornitore
    ADD CONSTRAINT fornitore_indirizzo_id_fkey FOREIGN KEY (indirizzo_id) REFERENCES greenify.indirizzo(id) ON UPDATE CASCADE;


--
-- Name: manager manager_mail_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.manager
    ADD CONSTRAINT manager_mail_fkey FOREIGN KEY (mail) REFERENCES greenify.utente(mail) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: negozio negozio_indirizzo_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.negozio
    ADD CONSTRAINT negozio_indirizzo_id_fkey FOREIGN KEY (indirizzo_id) REFERENCES greenify.indirizzo(id) ON UPDATE CASCADE;


--
-- Name: negozio negozio_manager_mail_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.negozio
    ADD CONSTRAINT negozio_manager_mail_fkey FOREIGN KEY (manager_mail) REFERENCES greenify.manager(mail) ON UPDATE CASCADE;


--
-- Name: orario orario_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.orario
    ADD CONSTRAINT orario_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: ordine_contiene_prodotto ordine_contiene_prodotto_ordine_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine_contiene_prodotto
    ADD CONSTRAINT ordine_contiene_prodotto_ordine_id_fkey FOREIGN KEY (ordine_id) REFERENCES greenify.ordine(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: ordine_contiene_prodotto ordine_contiene_prodotto_prodotto_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine_contiene_prodotto
    ADD CONSTRAINT ordine_contiene_prodotto_prodotto_id_fkey FOREIGN KEY (prodotto_id) REFERENCES greenify.prodotto(id) ON UPDATE CASCADE;


--
-- Name: ordine ordine_fornitore_piva_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine
    ADD CONSTRAINT ordine_fornitore_piva_fkey FOREIGN KEY (fornitore_piva) REFERENCES greenify.fornitore(p_iva) ON UPDATE CASCADE;


--
-- Name: ordine ordine_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.ordine
    ADD CONSTRAINT ordine_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE;


--
-- Name: rilascia rilascia_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.rilascia
    ADD CONSTRAINT rilascia_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE;


--
-- Name: rilascia rilascia_tessera_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.rilascia
    ADD CONSTRAINT rilascia_tessera_id_fkey FOREIGN KEY (tessera_id) REFERENCES greenify.tessera(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: tessera tessera_cliente_cf_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.tessera
    ADD CONSTRAINT tessera_cliente_cf_fkey FOREIGN KEY (cliente_cf) REFERENCES greenify.cliente(cf) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: tessera tessera_negozio_id_fkey; Type: FK CONSTRAINT; Schema: greenify; Owner: greenify_admin
--

ALTER TABLE ONLY greenify.tessera
    ADD CONSTRAINT tessera_negozio_id_fkey FOREIGN KEY (negozio_id) REFERENCES greenify.negozio(id) ON UPDATE CASCADE;


--
-- Name: TABLE "ClientiPremium"; Type: ACL; Schema: greenify; Owner: greenify_admin
--

GRANT SELECT ON TABLE greenify."ClientiPremium" TO PUBLIC;


--
-- Name: vm_statistiche_negozio_totali; Type: MATERIALIZED VIEW DATA; Schema: greenify; Owner: greenify_admin
--

REFRESH MATERIALIZED VIEW greenify.vm_statistiche_negozio_totali;


--
-- PostgreSQL database dump complete
--

