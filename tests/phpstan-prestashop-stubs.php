<?php

/**
 * Generated stubs for static analysis only — never loaded at runtime.
 *
 * PrestaShop declares its legacy classes as `DbCore`, `OrderCore` and so on,
 * and its autoloader creates the short alias (`class Db extends DbCore`) on
 * the fly. PHPStan never runs that autoloader, so without these every use of
 * a PrestaShop class reads as an unknown class and drowns out real findings.
 *
 * Regenerate after a PrestaShop upgrade:
 *   docker compose exec -T prestashop php /tmp/genstubs.php > \
 *     ledgerdirect/tests/phpstan-prestashop-stubs.php
 */

class Uploader extends UploaderCore {}
class PrestaShopLogger extends PrestaShopLoggerCore {}
class Feature extends FeatureCore {}
class SpecificPrice extends SpecificPriceCore {}
class CMSCategory extends CMSCategoryCore {}
class ImageType extends ImageTypeCore {}
class State extends StateCore {}
class Address extends AddressCore {}
class Image extends ImageCore {}
class AddressChecksum extends AddressChecksumCore {}
class EmployeeSession extends EmployeeSessionCore {}
class Shop extends ShopCore {}
class ShopGroup extends ShopGroupCore {}
class ShopUrl extends ShopUrlCore {}
class Chart extends ChartCore {}
class Upgrader extends UpgraderCore {}
class Tab extends TabCore {}
class Cart extends CartCore {}
class Tag extends TagCore {}
class Contact extends ContactCore {}
class DateRange extends DateRangeCore {}
class Page extends PageCore {}
class TaxRulesGroup extends TaxRulesGroupCore {}
abstract class TaxManagerModule extends TaxManagerModuleCore {}
class TaxManagerFactory extends TaxManagerFactoryCore {}
class TaxConfiguration extends TaxConfigurationCore {}
class TaxRulesTaxManager extends TaxRulesTaxManagerCore {}
class TaxCalculator extends TaxCalculatorCore {}
class Tax extends TaxCore {}
class TaxRule extends TaxRuleCore {}
class Zone extends ZoneCore {}
class ProductDownload extends ProductDownloadCore {}
class ProductSupplier extends ProductSupplierCore {}
class CustomerMessage extends CustomerMessageCore {}
class SearchEngine extends SearchEngineCore {}
class SpecificPriceFormatter extends SpecificPriceFormatterCore {}
class CustomerFormatter extends CustomerFormatterCore {}
class CustomerAddressFormatter extends CustomerAddressFormatterCore {}
abstract class AbstractForm extends AbstractFormCore {}
class CustomerLoginForm extends CustomerLoginFormCore {}
class CustomerAddressForm extends CustomerAddressFormCore {}
class FormField extends FormFieldCore {}
class CustomerPersister extends CustomerPersisterCore {}
class CustomerAddressPersister extends CustomerAddressPersisterCore {}
class CustomerForm extends CustomerFormCore {}
class CustomerLoginFormatter extends CustomerLoginFormatterCore {}
class DbPDO extends DbPDOCore {}
class DbQuery extends DbQueryCore {}
abstract class Db extends DbCore {}
class DbMySQLi extends DbMySQLiCore {}
class Curve extends CurveCore {}
class SpecificPriceRule extends SpecificPriceRuleCore {}
class CMS extends CMSCore {}
class CartRule extends CartRuleCore {}
class Notification extends NotificationCore {}
class CustomerAddress extends CustomerAddressCore {}
class TreeToolbarSearch extends TreeToolbarSearchCore {}
class TreeToolbarLink extends TreeToolbarLinkCore {}
class TreeToolbar extends TreeToolbarCore {}
abstract class TreeToolbarButton extends TreeToolbarButtonCore {}
class TreeToolbarSearchCategories extends TreeToolbarSearchCategoriesCore {}
class Tree extends TreeCore {}
class Risk extends RiskCore {}
class StockManagerFactory extends StockManagerFactoryCore {}
class WarehouseProductLocation extends WarehouseProductLocationCore {}
class SupplyOrderDetail extends SupplyOrderDetailCore {}
class SupplyOrderHistory extends SupplyOrderHistoryCore {}
class SupplyOrderState extends SupplyOrderStateCore {}
class Warehouse extends WarehouseCore {}
class SupplyOrder extends SupplyOrderCore {}
class StockMvt extends StockMvtCore {}
class SupplyOrderReceiptHistory extends SupplyOrderReceiptHistoryCore {}
class StockAvailable extends StockAvailableCore {}
abstract class StockManagerModule extends StockManagerModuleCore {}
class StockMvtWS extends StockMvtWSCore {}
class StockManager extends StockManagerCore {}
class Stock extends StockCore {}
class StockMvtReason extends StockMvtReasonCore {}
class Gender extends GenderCore {}
class ProductPresenterFactory extends ProductPresenterFactoryCore {}
class JsMinifier extends JsMinifierCore {}
class CssMinifier extends CssMinifierCore {}
class CccReducer extends CccReducerCore {}
class StylesheetManager extends StylesheetManagerCore {}
class JavascriptManager extends JavascriptManagerCore {}
abstract class AbstractAssetManager extends AbstractAssetManagerCore {}
class PhpEncryption extends PhpEncryptionCore {}
class Tools extends ToolsCore {}
class PrestaShopCollection extends PrestaShopCollectionCore {}
class ValidateConstraintTranslator extends ValidateConstraintTranslatorCore {}
class Delivery extends DeliveryCore {}
class QuickAccess extends QuickAccessCore {}
class Connection extends ConnectionCore {}
class SupplierAddress extends SupplierAddressCore {}
class Link extends LinkCore {}
class TranslatedConfiguration extends TranslatedConfigurationCore {}
class Language extends LanguageCore {}
class Validate extends ValidateCore {}
class PrestaShopBackup extends PrestaShopBackupCore {}
class RangePrice extends RangePriceCore {}
class RangeWeight extends RangeWeightCore {}
class ConfigurationKPI extends ConfigurationKPICore {}
class SmartyDevTemplate extends SmartyDevTemplateCore {}
class SmartyCustomTemplate extends SmartyCustomTemplateCore {}
class SmartyCustom extends SmartyCustomCore {}
class SmartyResourceModule extends SmartyResourceModuleCore {}
class TemplateFinder extends TemplateFinderCore {}
class SmartyResourceParent extends SmartyResourceParentCore {}
abstract class CarrierModule extends CarrierModuleCore {}
abstract class ModuleGraph extends ModuleGraphCore {}
abstract class Module extends ModuleCore {}
abstract class ModuleGridEngine extends ModuleGridEngineCore {}
abstract class ModuleGraphEngine extends ModuleGraphEngineCore {}
abstract class ModuleGrid extends ModuleGridCore {}
class Manufacturer extends ManufacturerCore {}
class Customer extends CustomerCore {}
class Media extends MediaCore {}
class LinkProxy extends LinkProxyCore {}
class CSV extends CSVCore {}
class FeatureFlag extends FeatureFlagCore {}
class ProductAttribute extends ProductAttributeCore {}
class ConfigurationTest extends ConfigurationTestCore {}
class Carrier extends CarrierCore {}
class CheckoutPaymentStep extends CheckoutPaymentStepCore {}
class CartChecksum extends CartChecksumCore {}
class PaymentOptionsFinder extends PaymentOptionsFinderCore {}
class CheckoutDeliveryStep extends CheckoutDeliveryStepCore {}
class CheckoutProcess extends CheckoutProcessCore {}
abstract class AbstractCheckoutStep extends AbstractCheckoutStepCore {}
class ConditionsToApproveFinder extends ConditionsToApproveFinderCore {}
class AddressValidator extends AddressValidatorCore {}
class DeliveryOptionsFinder extends DeliveryOptionsFinderCore {}
class CheckoutSession extends CheckoutSessionCore {}
class CheckoutAddressesStep extends CheckoutAddressesStepCore {}
class CheckoutPersonalInformationStep extends CheckoutPersonalInformationStepCore {}
class SupplyOrderStateLang extends SupplyOrderStateLangCore {}
class ProfileLang extends ProfileLangCore {}
class AttributeGroupLang extends AttributeGroupLangCore {}
class ThemeLang extends ThemeLangCore {}
class DataLang extends DataLangCore {}
class FeatureValueLang extends FeatureValueLangCore {}
class OrderMessageLang extends OrderMessageLangCore {}
class OrderReturnStateLang extends OrderReturnStateLangCore {}
class CmsCategoryLang extends CmsCategoryLangCore {}
class GenderLang extends GenderLangCore {}
class GroupLang extends GroupLangCore {}
class FeatureLang extends FeatureLangCore {}
class ContactLang extends ContactLangCore {}
class StockMvtReasonLang extends StockMvtReasonLangCore {}
class MetaLang extends MetaLangCore {}
class QuickAccessLang extends QuickAccessLangCore {}
class AttributeLang extends AttributeLangCore {}
class CategoryLang extends CategoryLangCore {}
class CarrierLang extends CarrierLangCore {}
class ConfigurationLang extends ConfigurationLangCore {}
class OrderStateLang extends OrderStateLangCore {}
class TabLang extends TabLangCore {}
class RiskLang extends RiskLangCore {}
class Supplier extends SupplierCore {}
class Country extends CountryCore {}
class ConnectionsSource extends ConnectionsSourceCore {}
class Combination extends CombinationCore {}
class Context extends ContextCore {}
class Product extends ProductCore {}
class Message extends MessageCore {}
class Configuration extends ConfigurationCore {}
class GroupReduction extends GroupReductionCore {}
class ProductSale extends ProductSaleCore {}
class FeatureValue extends FeatureValueCore {}
abstract class ObjectModel extends ObjectModelCore {}
class Employee extends EmployeeCore {}
class Customization extends CustomizationCore {}
class PhpEncryptionEngine extends PhpEncryptionEngineCore {}
class PrestaShopException extends PrestaShopExceptionCore {}
class PrestaShopObjectNotFoundException extends PrestaShopObjectNotFoundExceptionCore {}
class PrestaShopPaymentException extends PrestaShopPaymentExceptionCore {}
class PrestaShopDatabaseException extends PrestaShopDatabaseExceptionCore {}
class PrestaShopModuleException extends PrestaShopModuleExceptionCore {}
class Category extends CategoryCore {}
class Group extends GroupCore {}
class Cookie extends CookieCore {}
class Profile extends ProfileCore {}
class Currency extends CurrencyCore {}
class CMSRole extends CMSRoleCore {}
class Meta extends MetaCore {}
class ModuleFrontController extends ModuleFrontControllerCore {}
abstract class ProductListingFrontController extends ProductListingFrontControllerCore {}
abstract class ModuleAdminController extends ModuleAdminControllerCore {}
abstract class Controller extends ControllerCore {}
class FrontController extends FrontControllerCore {}
class AdminController extends AdminControllerCore {}
abstract class ProductPresentingFrontController extends ProductPresentingFrontControllerCore {}
class AddressFormat extends AddressFormatCore {}
class Pack extends PackCore {}
class CacheXcache extends CacheXcacheCore {}
class CacheMemcached extends CacheMemcachedCore {}
class CacheApc extends CacheApcCore {}
class CacheMemcache extends CacheMemcacheCore {}
abstract class Cache extends CacheCore {}
class CustomerThread extends CustomerThreadCore {}
class ProductAssembler extends ProductAssemblerCore {}
class Mail extends MailCore {}
class Alias extends AliasCore {}
class PDF extends PDFCore {}
class PDFGenerator extends PDFGeneratorCore {}
class HTMLTemplateSupplyOrderForm extends HTMLTemplateSupplyOrderFormCore {}
class HTMLTemplateOrderSlip extends HTMLTemplateOrderSlipCore {}
class HTMLTemplateOrderReturn extends HTMLTemplateOrderReturnCore {}
abstract class HTMLTemplate extends HTMLTemplateCore {}
class HTMLTemplateDeliverySlip extends HTMLTemplateDeliverySlipCore {}
class HTMLTemplateInvoice extends HTMLTemplateInvoiceCore {}
class Order extends OrderCore {}
class OrderSlip extends OrderSlipCore {}
class OrderDetail extends OrderDetailCore {}
class OrderInvoice extends OrderInvoiceCore {}
class OrderReturn extends OrderReturnCore {}
class OrderPayment extends OrderPaymentCore {}
class OrderCartRule extends OrderCartRuleCore {}
class OrderHistory extends OrderHistoryCore {}
class OrderMessage extends OrderMessageCore {}
class OrderReturnState extends OrderReturnStateCore {}
class OrderCarrier extends OrderCarrierCore {}
class OrderState extends OrderStateCore {}
class RequestSql extends RequestSqlCore {}
class HelperTreeCategories extends HelperTreeCategoriesCore {}
class HelperKpiRow extends HelperKpiRowCore {}
class HelperList extends HelperListCore {}
class HelperKpi extends HelperKpiCore {}
class HelperView extends HelperViewCore {}
class HelperImageUploader extends HelperImageUploaderCore {}
class HelperUploader extends HelperUploaderCore {}
class Helper extends HelperCore {}
class HelperShop extends HelperShopCore {}
class HelperCalendar extends HelperCalendarCore {}
class HelperOptions extends HelperOptionsCore {}
class HelperForm extends HelperFormCore {}
class HelperTreeShops extends HelperTreeShopsCore {}
class Dispatcher extends DispatcherCore {}
class Store extends StoreCore {}
class CustomizationField extends CustomizationFieldCore {}
class ManufacturerAddress extends ManufacturerAddressCore {}
class FileLogger extends FileLoggerCore {}
abstract class AbstractLogger extends AbstractLoggerCore {}
class Attachment extends AttachmentCore {}
class Translate extends TranslateCore {}
class CustomerSession extends CustomerSessionCore {}
class Search extends SearchCore {}
class WarehouseAddress extends WarehouseAddressCore {}
class Guest extends GuestCore {}
abstract class PaymentModule extends PaymentModuleCore {}
class Access extends AccessCore {}
class LocalizationPack extends LocalizationPackCore {}
class Hook extends HookCore {}
class ImageManager extends ImageManagerCore {}
class AttributeGroup extends AttributeGroupCore {}
class WebserviceSpecificManagementImages extends WebserviceSpecificManagementImagesCore {}
class WebserviceOutputXML extends WebserviceOutputXMLCore {}
class WebserviceKey extends WebserviceKeyCore {}
class WebserviceSpecificManagementAttachments extends WebserviceSpecificManagementAttachmentsCore {}
class WebserviceException extends WebserviceExceptionCore {}
class WebserviceOutputJSON extends WebserviceOutputJSONCore {}
class WebserviceRequest extends WebserviceRequestCore {}
class WebserviceOutputBuilder extends WebserviceOutputBuilderCore {}
class WebserviceSpecificManagementSearch extends WebserviceSpecificManagementSearchCore {}
class AdminCustomerThreadsController extends AdminCustomerThreadsControllerCore {}
class AdminGroupsController extends AdminGroupsControllerCore {}
class AdminTaxRulesGroupController extends AdminTaxRulesGroupControllerCore {}
class AdminShopGroupController extends AdminShopGroupControllerCore {}
class AdminStatsController extends AdminStatsControllerCore {}
class AdminTabsController extends AdminTabsControllerCore {}
class AdminReturnController extends AdminReturnControllerCore {}
class AdminTranslationsController extends AdminTranslationsControllerCore {}
class AdminDashboardController extends AdminDashboardControllerCore {}
class AdminSearchConfController extends AdminSearchConfControllerCore {}
class AdminImportController extends AdminImportControllerCore {}
class AdminStoresController extends AdminStoresControllerCore {}
class AdminSpecificPriceRuleController extends AdminSpecificPriceRuleControllerCore {}
class AdminPdfController extends AdminPdfControllerCore {}
class AdminSearchController extends AdminSearchControllerCore {}
class AdminCarrierWizardController extends AdminCarrierWizardControllerCore {}
class AdminCartRulesController extends AdminCartRulesControllerCore {}
class AdminQuickAccessesController extends AdminQuickAccessesControllerCore {}
class AdminModulesPositionsController extends AdminModulesPositionsControllerCore {}
class DummyAdminController extends DummyAdminControllerCore {}
class AdminCarriersController extends AdminCarriersControllerCore {}
class AdminAccessController extends AdminAccessControllerCore {}
class AdminNotFoundController extends AdminNotFoundControllerCore {}
class AdminShopUrlController extends AdminShopUrlControllerCore {}
class AdminShopController extends AdminShopControllerCore {}
class AdminCountriesController extends AdminCountriesControllerCore {}
abstract class AdminStatsTabController extends AdminStatsTabControllerCore {}
class AdminTagsController extends AdminTagsControllerCore {}
class BoOrder extends BoOrderCore {}
class AuthController extends AuthControllerCore {}
class OrderSlipController extends OrderSlipControllerCore {}
class CartController extends CartControllerCore {}
class HistoryController extends HistoryControllerCore {}
class AddressController extends AddressControllerCore {}
class IdentityController extends IdentityControllerCore {}
class ProductController extends ProductControllerCore {}
class OrderConfirmationController extends OrderConfirmationControllerCore {}
class ContactController extends ContactControllerCore {}
class OrderController extends OrderControllerCore {}
class GetFileController extends GetFileControllerCore {}
class MyAccountController extends MyAccountControllerCore {}
class GuestTrackingController extends GuestTrackingControllerCore {}
class DiscountController extends DiscountControllerCore {}
class PasswordController extends PasswordControllerCore {}
class PdfInvoiceController extends PdfInvoiceControllerCore {}
class ChangeCurrencyController extends ChangeCurrencyControllerCore {}
class OrderFollowController extends OrderFollowControllerCore {}
class IndexController extends IndexControllerCore {}
class PdfOrderReturnController extends PdfOrderReturnControllerCore {}
class OrderDetailController extends OrderDetailControllerCore {}
class AddressesController extends AddressesControllerCore {}
class UploadController extends UploadControllerCore {}
class StoresController extends StoresControllerCore {}
class OrderReturnController extends OrderReturnControllerCore {}
class PdfOrderSlipController extends PdfOrderSlipControllerCore {}
class PageNotFoundController extends PageNotFoundControllerCore {}
class SearchController extends SearchControllerCore {}
class CategoryController extends CategoryControllerCore {}
class SupplierController extends SupplierControllerCore {}
class PricesDropController extends PricesDropControllerCore {}
class BestSalesController extends BestSalesControllerCore {}
class NewProductsController extends NewProductsControllerCore {}
class ManufacturerController extends ManufacturerControllerCore {}
class RegistrationController extends RegistrationControllerCore {}
class AttachmentController extends AttachmentControllerCore {}
class SitemapController extends SitemapControllerCore {}
class CmsController extends CmsControllerCore {}
class StatisticsController extends StatisticsControllerCore {}
