/* Technas Pay dans le paiement en blocs : les deux moyens, sans étape de build. */
(function () {
  const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
  const { getSetting } = window.wc.wcSettings;
  const { createElement } = window.wp.element;
  const { decodeEntities } = window.wp.htmlEntities;

  ["technas_pay_mobile_money", "technas_pay_card"].forEach(function (name) {
    const data = getSetting(name + "_data", {});
    if (!data.title) return; // moyen inactif (clé absente, devise, compte carte) : rien à montrer
    const label = decodeEntities(data.title || "");
    const Content = function () {
      return createElement("div", null, decodeEntities(data.description || ""));
    };
    registerPaymentMethod({
      name: name,
      label: createElement("span", null, label),
      ariaLabel: label,
      content: createElement(Content, null),
      edit: createElement(Content, null),
      canMakePayment: function () {
        return true;
      },
      supports: { features: data.supports || ["products"] },
    });
  });
})();
