[MESSAGE]

<div class="row">
  <div class="col-lg-8">
    <h2>Lexware Office API-Schl&uuml;ssel</h2>
    <form method="post" action="">
      <fieldset>
        <legend>API-Schl&uuml;ssel</legend>
        <p>
          <label for="lexware-api-key"><strong>API-Schl&uuml;ssel</strong></label><br>
          <input type="password" name="api_key" id="lexware-api-key" value="" placeholder="[API_KEY_PLACEHOLDER]" style="width: 100%;">
        </p>
        <p class="hint">[API_KEY_HINT]</p>
        <p>
          <input type="submit" class="btnBlue" name="save" value="Speichern">
        </p>
      </fieldset>
    </form>

    <form method="post" action="">
      <fieldset>
        <legend>Standard-Erl&ouml;skategorie</legend>
        <p>
          <label for="lexware-default-category"><strong>categoryId</strong></label><br>
          <input type="text" name="default_category_id" id="lexware-default-category" value="[DEFAULT_CATEGORY_ID]" placeholder="00000000-0000-0000-0000-000000000000" style="width: 100%;">
        </p>
        <p class="hint">[DEFAULT_CATEGORY_HINT]</p>
        <p>
          <input type="submit" class="btnBlue" name="save_category" value="Kategorie speichern">
        </p>
      </fieldset>
    </form>

    [DELETE_SECTION]
  </div>
</div>
