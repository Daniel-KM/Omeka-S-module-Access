# Cantaloupe delegate script for Omeka S module Access.
#
# Calls Omeka endpoint /access/authorize to check whether the current client
# is allowed to access a given media. Keeps an in-process Ruby cache to avoid
# hitting PHP on every tile request.
#
# Install:
#   1. Copy to /etc/cantaloupe/delegates.rb (or mount via docker-compose).
#   2. In cantaloupe.properties:
#        delegate_script.enabled  = true
#        delegate_script.pathname = /etc/cantaloupe/delegates.rb
#   3. Add the Cantaloupe container IP(s) to the "Trusted proxies"
#      setting of module Access, so X-Forwarded-For is honored.
#   4. Restart Cantaloupe.
#
# Single instance: set OMEKA_HOST / OMEKA_UPSTREAM below.
# Multi-instance:  derive OMEKA_HOST from context['request_headers']['X-Forwarded-Host'].

require 'net/http'
require 'uri'

class CustomDelegate

  attr_accessor :context

  # Omeka endpoint reachable from the Cantaloupe container.
  OMEKA_HOST     = 'omeka.example.org'
  OMEKA_UPSTREAM = 'omeka'
  OMEKA_PORT     = 80

  # Cache TTL for authorization decisions (seconds).
  CACHE_TTL = 120
  CACHE_MAX = 2000

  @@cache = {}
  @@mutex = Mutex.new

  # Called before any image processing. Return false => 403.
  def pre_authorize(options = {})
    identifier = context['identifier']
    return true if identifier.nil? || identifier.empty?

    client_ip = context['client_ip'] || 'unknown'
    key = "#{identifier}|#{client_ip}"
    now = Time.now.to_i

    @@mutex.synchronize do
      hit = @@cache[key]
      return hit[:allowed] if hit && hit[:expires] > now
    end

    allowed = fetch_decision(identifier, client_ip)

    @@mutex.synchronize do
      @@cache[key] = { allowed: allowed, expires: now + CACHE_TTL }
      if @@cache.size > CACHE_MAX
        @@cache.delete_if { |_, v| v[:expires] < now }
      end
    end

    allowed
  end

  def authorize(options = {})
    pre_authorize(options)
  end

  # Stubs required by Cantaloupe delegate interface. Return defaults so that
  # every IIIF endpoint (info.json, images, etc.) works without custom logic.
  def deserialize_meta_identifier(meta_identifier); {}; end
  def serialize_meta_identifier(components); ''; end
  def extra_iiif2_information_response_keys(options = {}); {}; end
  def extra_iiif3_information_response_keys(options = {}); {}; end
  def source(options = {}); nil; end
  def azurestoragesource_blob_key(options = {}); nil; end
  def filesystemsource_pathname(options = {}); nil; end
  def httpsource_resource_info(options = {}); nil; end
  def jdbcsource_database_identifier(options = {}); nil; end
  def jdbcsource_last_modified(options = {}); nil; end
  def jdbcsource_media_type(options = {}); nil; end
  def jdbcsource_lookup_sql(options = {}); nil; end
  def s3source_object_info(options = {}); nil; end
  def overlay(options = {}); nil; end
  def redactions(options = {}); []; end
  def metadata(options = {}); nil; end

  private

  def fetch_decision(identifier, client_ip)
    # Use "filename" so both "<storage_id>" and "<storage_id>.<ext>" work.
    uri = URI("http://#{OMEKA_UPSTREAM}:#{OMEKA_PORT}/access/authorize")
    uri.query = URI.encode_www_form(filename: identifier)

    req = Net::HTTP::Get.new(uri)
    req['Host']            = OMEKA_HOST
    req['X-Forwarded-For'] = client_ip

    if context['cookies'] && !context['cookies'].empty?
      req['Cookie'] = context['cookies'].map { |k, v| "#{k}=#{v}" }.join('; ')
    end

    if context['request_uri']
      qs = URI(context['request_uri']).query rescue nil
      req['X-Omeka-Access'] = qs if qs
    end

    begin
      res = Net::HTTP.start(uri.hostname, uri.port, open_timeout: 2, read_timeout: 3) { |h| h.request(req) }
      res.code.to_i == 200
    rescue StandardError
      # On backend failure, default to allow to avoid a full IIIF outage.
      # Change to "false" if you prefer fail-closed.
      true
    end
  end
end
